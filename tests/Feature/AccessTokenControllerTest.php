<?php

namespace Laravel\Passport\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Client;
use Laravel\Passport\Database\Factories\ClientFactory;
use Laravel\Passport\HasApiTokens;
use Laravel\Passport\Passport;
use Laravel\Passport\PersonalAccessTokenFactory;
use Laravel\Passport\Token;

class AccessTokenControllerTest extends PassportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('password');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        Passport::setDefaultScope([]);

        parent::tearDown();
    }

    protected function getUserClass()
    {
        return User::class;
    }

    public function testGettingAccessTokenWithClientCredentialsGrant()
    {
        $this->withoutExceptionHandling();

        $user = new User();
        $user->email = 'foo@gmail.com';
        $user->password = $this->app->make(Hasher::class)->make('foobar123');
        $user->save();

        /** @var Client $client */
        $client = ClientFactory::new()->asClientCredentials()->create(['user_id' => $user->getKey()]);

        $response = $this->post(
            '/oauth/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => $client->getKey(),
                'client_secret' => $client->secret,
            ]
        );

        $response->assertOk();

        $response->assertHeader('pragma', 'no-cache');
        $response->assertHeader('cache-control', 'no-store, private');
        $response->assertHeader('content-type', 'application/json; charset=UTF-8');

        $decodedResponse = $response->decodeResponseJson()->json();

        $this->assertArrayHasKey('token_type', $decodedResponse);
        $this->assertArrayHasKey('expires_in', $decodedResponse);
        $this->assertArrayHasKey('access_token', $decodedResponse);
        $this->assertSame('Bearer', $decodedResponse['token_type']);
        $expiresInSeconds = 31622400;
        $this->assertEqualsWithDelta($expiresInSeconds, $decodedResponse['expires_in'], 5);

        $token = $this->app->make(PersonalAccessTokenFactory::class)->findAccessToken($decodedResponse);
        $this->assertInstanceOf(Token::class, $token);
        $this->assertTrue($token->client->is($client));
        $this->assertFalse($token->revoked);
        $this->assertNull($token->name);
        $this->assertNull($token->user_id);
        $this->assertLessThanOrEqual(5, CarbonImmutable::now()->addSeconds($expiresInSeconds)->diffInSeconds($token->expires_at));
    }

    public function testGettingAccessTokenWithClientCredentialsGrantInvalidClientSecret()
    {
        $user = new User();
        $user->email = 'foo@gmail.com';
        $user->password = $this->app->make(Hasher::class)->make('foobar123');
        $user->save();

        /** @var Client $client */
        $client = ClientFactory::new()->asClientCredentials()->create(['user_id' => $user->getKey()]);

        $response = $this->post(
            '/oauth/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => $client->getKey(),
                'client_secret' => $client->secret.'foo',
            ]
        );

        $response->assertStatus(401);

        $response->assertHeader('cache-control', 'no-cache, private');
        $response->assertHeader('content-type', 'application/json');

        $decodedResponse = $response->decodeResponseJson()->json();

        $this->assertArrayNotHasKey('token_type', $decodedResponse);
        $this->assertArrayNotHasKey('expires_in', $decodedResponse);
        $this->assertArrayNotHasKey('access_token', $decodedResponse);

        $this->assertArrayHasKey('error', $decodedResponse);
        $this->assertSame('invalid_client', $decodedResponse['error']);
        $this->assertArrayHasKey('error_description', $decodedResponse);
        $this->assertSame('Client authentication failed', $decodedResponse['error_description']);
        $this->assertArrayNotHasKey('hint', $decodedResponse);
        $this->assertArrayHasKey('message', $decodedResponse);
        $this->assertSame('Client authentication failed', $decodedResponse['message']);

        $this->assertSame(0, Token::count());
    }

    public function testGettingAccessTokenWithClientCredentialsGrantUsingDefaultScope()
    {
        $this->withoutExceptionHandling();

        $user = new User();
        $user->email = 'foo@gmail.com';
        $user->password = $this->app->make(Hasher::class)->make('foobar123');
        $user->save();

        Passport::useDefaultScopes();
        Passport::setDefaultScope([
            'foo' => 'It requests foo access',
            'bar' => 'it requests bar access',
        ]);

        /** @var Client $client */
        $client = ClientFactory::new()->asClientCredentials()->create(['user_id' => $user->getKey()]);

        $response = $this->post(
            '/oauth/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => $client->getKey(),
                'client_secret' => $client->secret,
            ]
        );

        $response->assertOk();

        $response->assertHeader('pragma', 'no-cache');
        $response->assertHeader('cache-control', 'no-store, private');
        $response->assertHeader('content-type', 'application/json; charset=UTF-8');

        $decodedResponse = $response->decodeResponseJson()->json();

        $this->assertArrayHasKey('token_type', $decodedResponse);
        $this->assertArrayHasKey('expires_in', $decodedResponse);
        $this->assertArrayHasKey('access_token', $decodedResponse);
        $this->assertSame('Bearer', $decodedResponse['token_type']);
        $expiresInSeconds = 31622400;
        $this->assertEqualsWithDelta($expiresInSeconds, $decodedResponse['expires_in'], 5);

        $token = $this->app->make(PersonalAccessTokenFactory::class)->findAccessToken($decodedResponse);
        $this->assertInstanceOf(Token::class, $token);
        $this->assertTrue($token->client->is($client));
        $this->assertFalse($token->revoked);
        $this->assertNull($token->name);
        $this->assertNull($token->user_id);
        $this->assertEquals(['foo', 'bar'], $token->scopes);
        $this->assertLessThanOrEqual(5, CarbonImmutable::now()->addSeconds($expiresInSeconds)->diffInSeconds($token->expires_at));
    }

    public function testGettingAccessTokenWithPasswordGrant()
    {
        $this->withoutExceptionHandling();

        $password = 'foobar123';
        $user = new User();
        $user->email = 'foo@gmail.com';
        $user->password = $this->app->make(Hasher::class)->make($password);
        $user->save();

        /** @var Client $client */
        $client = ClientFactory::new()->asPasswordClient()->create(['user_id' => $user->getKey()]);

        $response = $this->post(
            '/oauth/token',
            [
                'grant_type' => 'password',
                'client_id' => $client->getKey(),
                'client_secret' => $client->secret,
                'username' => $user->email,
                'password' => $password,
            ]
        );

        $response->assertOk();

        $response->assertHeader('pragma', 'no-cache');
        $response->assertHeader('cache-control', 'no-store, private');
        $response->assertHeader('content-type', 'application/json; charset=UTF-8');

        $decodedResponse = $response->decodeResponseJson()->json();

        $this->assertArrayHasKey('token_type', $decodedResponse);
        $this->assertArrayHasKey('expires_in', $decodedResponse);
        $this->assertArrayHasKey('access_token', $decodedResponse);
        $this->assertArrayHasKey('refresh_token', $decodedResponse);
        $this->assertSame('Bearer', $decodedResponse['token_type']);
        $expiresInSeconds = 31622400;
        $this->assertEqualsWithDelta($expiresInSeconds, $decodedResponse['expires_in'], 5);

        $token = $this->app->make(PersonalAccessTokenFactory::class)->findAccessToken($decodedResponse);
        $this->assertInstanceOf(Token::class, $token);
        $this->assertFalse($token->revoked);
        $this->assertTrue($token->user->is($user));
        $this->assertTrue($token->client->is($client));
        $this->assertNull($token->name);
        $this->assertLessThanOrEqual(5, CarbonImmutable::now()->addSeconds($expiresInSeconds)->diffInSeconds($token->expires_at));
    }

    public function testGettingAccessTokenWithPasswordGrantWithInvalidPassword()
    {
        $password = 'foobar123';
        $user = new User();
        $user->email = 'foo@gmail.com';
        $user->password = $this->app->make(Hasher::class)->make($password);
        $user->save();

        /** @var Client $client */
        $client = ClientFactory::new()->asPasswordClient()->create(['user_id' => $user->getKey()]);

        $response = $this->post(
            '/oauth/token',
            [
                'grant_type' => 'password',
                'client_id' => $client->getKey(),
                'client_secret' => $client->secret,
                'username' => $user->email,
                'password' => $password.'foo',
            ]
        );

        $response->assertStatus(400);

        $response->assertHeader('cache-control', 'no-cache, private');
        $response->assertHeader('content-type', 'application/json');

        $decodedResponse = $response->decodeResponseJson()->json();

        $this->assertArrayNotHasKey('token_type', $decodedResponse);
        $this->assertArrayNotHasKey('expires_in', $decodedResponse);
        $this->assertArrayNotHasKey('access_token', $decodedResponse);
        $this->assertArrayNotHasKey('refresh_token', $decodedResponse);
        $this->assertArrayNotHasKey('hint', $decodedResponse);

        $this->assertArrayHasKey('error', $decodedResponse);
        $this->assertSame('invalid_grant', $decodedResponse['error']);
        $this->assertArrayHasKey('error_description', $decodedResponse);
        $this->assertArrayHasKey('message', $decodedResponse);

        $this->assertSame(0, Token::count());
    }

    public function testGettingAccessTokenWithPasswordGrantWithInvalidClientSecret()
    {
        $password = 'foobar123';
        $user = new User();
        $user->email = 'foo@gmail.com';
        $user->password = $this->app->make(Hasher::class)->make($password);
        $user->save();

        /** @var Client $client */
        $client = ClientFactory::new()->asPasswordClient()->create(['user_id' => $user->getKey()]);

        $response = $this->post(
            '/oauth/token',
            [
                'grant_type' => 'password',
                'client_id' => $client->getKey(),
                'client_secret' => $client->secret.'foo',
                'username' => $user->email,
                'password' => $password,
            ]
        );

        $response->assertStatus(401);

        $response->assertHeader('cache-control', 'no-cache, private');
        $response->assertHeader('content-type', 'application/json');

        $decodedResponse = $response->decodeResponseJson()->json();

        $this->assertArrayNotHasKey('token_type', $decodedResponse);
        $this->assertArrayNotHasKey('expires_in', $decodedResponse);
        $this->assertArrayNotHasKey('access_token', $decodedResponse);
        $this->assertArrayNotHasKey('refresh_token', $decodedResponse);

        $this->assertArrayHasKey('error', $decodedResponse);
        $this->assertSame('invalid_client', $decodedResponse['error']);
        $this->assertArrayHasKey('error_description', $decodedResponse);
        $this->assertSame('Client authentication failed', $decodedResponse['error_description']);
        $this->assertArrayNotHasKey('hint', $decodedResponse);
        $this->assertArrayHasKey('message', $decodedResponse);
        $this->assertSame('Client authentication failed', $decodedResponse['message']);

        $this->assertSame(0, Token::count());
    }

    public function testGettingCustomResponseType()
    {
        $this->withoutExceptionHandling();
        Passport::$authorizationServerResponseType = new IdTokenResponse('foo_bar_open_id_token');

        $user = new User();
        $user->email = 'foo@gmail.com';
        $user->password = $this->app->make(Hasher::class)->make('foobar123');
        $user->save();

        /** @var Client $client */
        $client = ClientFactory::new()->asClientCredentials()->create(['user_id' => $user->getKey()]);

        $response = $this->post(
            '/oauth/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => $client->getKey(),
                'client_secret' => $client->secret,
            ]
        );

        $response->assertOk();

        $decodedResponse = $response->decodeResponseJson()->json();

        $this->assertArrayHasKey('id_token', $decodedResponse);
        $this->assertSame('foo_bar_open_id_token', $decodedResponse['id_token']);
    }
}

class User extends \Illuminate\Foundation\Auth\User
{
    use HasApiTokens;
}

class IdTokenResponse extends \League\OAuth2\Server\ResponseTypes\BearerTokenResponse
{
    /**
     * @var string Id token.
     */
    protected $idToken;

    /**
     * @param  string  $idToken
     */
    public function __construct($idToken)
    {
        $this->idToken = $idToken;
    }

    /**
     * @inheritdoc
     */
    protected function getExtraParams(\League\OAuth2\Server\Entities\AccessTokenEntityInterface $accessToken)
    {
        return [
            'id_token' => $this->idToken,
        ];
    }
}
