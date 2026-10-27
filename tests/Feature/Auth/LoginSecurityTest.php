<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * SEC-02 & SEC-04: the login/session guarantees that keep a stolen or guessed credential from
 * granting permanent or unbounded access.
 *   - A token stops working the moment the user logs out (server-side revocation).
 *   - A token stops working once it is older than the configured expiration window.
 *   - The login endpoint caps credential-guessing attempts.
 */
final class LoginSecurityTest extends VeterinaryTestCase
{
    private const EMAIL = 'login-security@prueba.com';
    private const PASSWORD = 'password123';

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::firstOrCreate(
            ['email' => self::EMAIL],
            ['name' => 'Login Security', 'password' => Hash::make(self::PASSWORD)],
        );

        $this->linkUserToCompany($user, (int) $this->company->id);
    }

    /**
     * Issues a real Bearer token the way the API does, but without going through /login — which
     * would also open a first-party session the test HTTP client then reuses via cookie, masking
     * the token path we are actually asserting (the SPA and mobile scanner authenticate by Bearer).
     */
    private function issueToken(): string
    {
        return User::where('email', self::EMAIL)->firstOrFail()
            ->createToken('test')->plainTextToken;
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * The Sanctum guard caches the resolved user on its instance, and the test HTTP client reuses
     * that instance across requests. In production every request is a fresh process that re-validates
     * the token, so we forget the guards to reproduce that and prove revocation/expiry are enforced.
     */
    private function asFreshRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_logout_revokes_the_token_server_side(): void
    {
        $token = $this->issueToken();

        // The token works while the session is active.
        $this->getJson($this->url('/me'), $this->bearer($token))->assertOk();

        // Logout deletes the token server-side (204 No Content per the API's HTTP semantics).
        $this->postJson($this->url('/logout'), [], $this->bearer($token))->assertNoContent();

        // The very same token is now useless: the attack window is closed, not just the browser copy.
        $this->asFreshRequest();
        $this->getJson($this->url('/me'), $this->bearer($token))->assertUnauthorized();

        $this->assertNull(PersonalAccessToken::findToken($token));
    }

    public function test_a_token_older_than_the_expiration_window_is_rejected(): void
    {
        $token = $this->issueToken();

        $this->getJson($this->url('/me'), $this->bearer($token))->assertOk();

        // Age the token beyond the configured window. Sanctum derives expiry from created_at + the
        // `sanctum.expiration` config, so a token that never expired before now does.
        $minutes = (int) config('sanctum.expiration');
        $this->assertGreaterThan(0, $minutes, 'sanctum.expiration must be set (SEC-02).');

        PersonalAccessToken::findToken($token)
            ->forceFill(['created_at' => now()->subMinutes($minutes + 1)])
            ->save();

        $this->asFreshRequest();
        $this->getJson($this->url('/me'), $this->bearer($token))->assertUnauthorized();
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        // Five wrong-password attempts are rejected as validation errors (no lockout yet).
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->url('/login'), [
                'email' => self::EMAIL,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // The sixth attempt is throttled, even with the correct password: the limiter caps the
        // rate before credentials are ever checked.
        $this->postJson($this->url('/login'), [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ])->assertStatus(429);
    }
}
