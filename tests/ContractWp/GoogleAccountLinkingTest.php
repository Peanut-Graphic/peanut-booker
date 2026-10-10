<?php
/**
 * Google sign-in account linking on a real WordPress (real users, roles,
 * capabilities and usermeta). Drives the real Peanut_Booker_Google_Auth
 * user-processing step with the profile Google returns; only the HTTP calls to
 * Google are out of scope.
 */
namespace Peanut_Booker\Tests\ContractWp;

use Peanut_Booker_Google_Auth;
use ReflectionClass;
use ReflectionMethod;
use WP_Error;
use WP_UnitTestCase;

final class GoogleAccountLinkingTest extends WP_UnitTestCase {
    private const GOOGLE_ID = '109876543210987654321';

    public static function wpSetUpBeforeClass($factory): void {
        require_once dirname(__DIR__, 2) . '/includes/class-google-auth.php';
    }

    public function set_up(): void {
        parent::set_up();
        wp_set_current_user(0);
        // Logging in must not try to send real cookies from the CLI.
        add_filter('send_auth_cookies', '__return_false');
    }

    /** @return true|WP_Error */
    private function process(array $google_user, string $action = 'login') {
        $auth = (new ReflectionClass(Peanut_Booker_Google_Auth::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Peanut_Booker_Google_Auth::class, 'process_google_user');
        return $method->invoke($auth, $google_user, $action);
    }

    private function profile(string $email, $verified = true, string $key = 'verified_email'): array {
        $profile = ['id' => self::GOOGLE_ID, 'email' => $email, 'name' => 'Synthetic Person'];
        if (null !== $verified) {
            $profile[$key] = $verified;
        }
        return $profile;
    }

    private function user(string $role, string $email): int {
        return self::factory()->user->create(['role' => $role, 'user_email' => $email]);
    }

    private function assertNotLinkedOrLoggedIn(int $user_id, $result, string $why): void {
        $this->assertInstanceOf(WP_Error::class, $result, $why);
        $this->assertSame('', get_user_meta($user_id, 'pb_google_id', true), $why . ': no Google ID linked');
        $this->assertSame(0, get_current_user_id(), $why . ': nobody logged in');
    }

    public function test_unverified_google_email_never_links_or_logs_in(): void {
        $user = $this->user('subscriber', 'victim@example.com');
        foreach ([false, null, 'false', 0] as $claim) {
            $this->assertNotLinkedOrLoggedIn($user, $this->process($this->profile('victim@example.com', $claim)), 'verified_email=' . var_export($claim, true));
        }
    }

    public function test_unverified_google_email_cannot_create_an_account(): void {
        $before = count_users()['total_users'];
        $result = $this->process($this->profile('new-person@example.com', false), 'signup_customer');
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertFalse(get_user_by('email', 'new-person@example.com'));
        $this->assertSame($before, count_users()['total_users']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function privilegedAccounts(): array {
        return [
            'administrator' => ['administrator', ''],
            'editor' => ['editor', ''],
            'subscriber with pb_manage_bookings' => ['subscriber', 'pb_manage_bookings'],
            'subscriber with pb_manage_payouts' => ['subscriber', 'pb_manage_payouts'],
        ];
    }

    /** @dataProvider privilegedAccounts */
    public function test_privileged_accounts_are_never_auto_linked(string $role, string $cap): void {
        $user = $this->user($role, 'staff@example.com');
        if ('' !== $cap) {
            get_user_by('id', $user)->add_cap($cap);
        }
        foreach (['login', 'signup_customer', 'signup_performer'] as $action) {
            $this->assertNotLinkedOrLoggedIn($user, $this->process($this->profile('staff@example.com'), $action), "$role/$cap via $action");
        }
    }

    public function test_verified_email_links_an_ordinary_account(): void {
        $user = $this->user('subscriber', 'customer@example.com');
        $this->assertTrue($this->process($this->profile('customer@example.com')));
        $this->assertSame(self::GOOGLE_ID, get_user_meta($user, 'pb_google_id', true));
        $this->assertSame($user, get_current_user_id());
    }

    public function test_oidc_email_verified_claim_is_accepted(): void {
        $user = $this->user('subscriber', 'oidc@example.com');
        $this->assertTrue($this->process($this->profile('oidc@example.com', true, 'email_verified')));
        $this->assertSame($user, get_current_user_id());
    }

    public function test_account_already_linked_to_another_google_id_is_not_relinked(): void {
        $user = $this->user('subscriber', 'linked@example.com');
        update_user_meta($user, 'pb_google_id', 'some-other-google-account');
        $result = $this->process($this->profile('linked@example.com'));
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('some-other-google-account', get_user_meta($user, 'pb_google_id', true));
        $this->assertSame(0, get_current_user_id());
    }

    public function test_admin_who_linked_from_their_profile_can_still_sign_in_with_google(): void {
        $admin = $this->user('administrator', 'admin@example.com');
        update_user_meta($admin, 'pb_google_id', self::GOOGLE_ID);
        $this->assertTrue($this->process($this->profile('admin@example.com')));
        $this->assertSame($admin, get_current_user_id());
    }

    public function test_logged_in_admin_can_link_from_their_profile(): void {
        $admin = $this->user('administrator', 'admin2@example.com');
        wp_set_current_user($admin);
        $this->assertTrue($this->process($this->profile('admin2@example.com'), 'link'));
        $this->assertSame(self::GOOGLE_ID, get_user_meta($admin, 'pb_google_id', true));
    }

    public function test_link_refuses_a_google_account_already_connected_elsewhere(): void {
        $owner = $this->user('subscriber', 'owner@example.com');
        update_user_meta($owner, 'pb_google_id', self::GOOGLE_ID);
        $other = $this->user('subscriber', 'other@example.com');
        wp_set_current_user($other);
        $this->assertInstanceOf(WP_Error::class, $this->process($this->profile('owner@example.com'), 'link'));
        $this->assertSame('', get_user_meta($other, 'pb_google_id', true));
    }
}
