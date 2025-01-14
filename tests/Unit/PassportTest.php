<?php

namespace Laravel\Passport\Tests\Unit;

use Laravel\Passport\AuthCode;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\PersonalAccessClient;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use PHPUnit\Framework\TestCase;

class PassportTest extends TestCase
{
    protected function tearDown(): void
    {
        Passport::setDefaultScope([]);
        parent::tearDown();
    }

    public function test_scopes_can_be_managed()
    {
        Passport::tokensCan([
            'user' => 'get user information',
        ]);

        $this->assertTrue(Passport::hasScope('user'));
        $this->assertEquals(['user'], Passport::scopeIds());
        $this->assertSame('user', Passport::scopes()[0]->id);
    }

    public function test_auth_code_instance_can_be_created()
    {
        $authCode = Passport::authCode();

        $this->assertInstanceOf(AuthCode::class, $authCode);
        $this->assertInstanceOf(Passport::authCodeModel(), $authCode);
    }

    public function test_client_instance_can_be_created()
    {
        $client = Passport::client();

        $this->assertInstanceOf(Client::class, $client);
        $this->assertInstanceOf(Passport::clientModel(), $client);
    }

    public function test_personal_access_client_instance_can_be_created()
    {
        $client = Passport::personalAccessClient();

        $this->assertInstanceOf(PersonalAccessClient::class, $client);
        $this->assertInstanceOf(Passport::personalAccessClientModel(), $client);
    }

    public function test_missing_personal_access_client_is_reported()
    {
        $this->expectException('RuntimeException');

        Passport::usePersonalAccessClientModel(PersonalAccessClientStub::class);

        $clientRepository = new ClientRepository;
        $clientRepository->personalAccessClient();
    }

    public function test_token_instance_can_be_created()
    {
        $token = Passport::token();

        $this->assertInstanceOf(Token::class, $token);
        $this->assertInstanceOf(Passport::tokenModel(), $token);
    }

    public function test_refresh_token_instance_can_be_created()
    {
        $refreshToken = Passport::refreshToken();

        $this->assertInstanceOf(RefreshToken::class, $refreshToken);
        $this->assertInstanceOf(Passport::refreshTokenModel(), $refreshToken);
    }

    public function test_refresh_token_model_can_be_changed()
    {
        Passport::useRefreshTokenModel(RefreshTokenStub::class);

        $refreshToken = Passport::refreshToken();

        $this->assertInstanceOf(RefreshTokenStub::class, $refreshToken);
        $this->assertInstanceOf(Passport::refreshTokenModel(), $refreshToken);

        Passport::useRefreshTokenModel(RefreshToken::class);
    }

    public function test_default_scopes_can_be_managed()
    {
        Passport::useDefaultScopes();
        Passport::setDefaultScope([
            'foo' => 'It requests foo access',
        ]);

        $this->assertTrue(Passport::hasScope('foo'));
    }

    public function test_default_scopes_are_not_included_if_use_default_is_not_true()
    {
        Passport::$useDefaultScopes = false;
        Passport::setDefaultScope([
            'foo' => 'It requests foo access',
        ]);

        $this->assertFalse(Passport::hasScope('foo'));
    }
}

class PersonalAccessClientStub
{
    public function exists()
    {
        return false;
    }
}

class RefreshTokenStub
{
}
