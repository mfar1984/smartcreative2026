<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * Builds the password validation rule from the admin-configured policy.
 *
 * Before the Security tab existed, every Form Request that set a password carried
 * the same literal rule: Password::min(10)->letters()->numbers()->symbols(). That
 * literal now lives in one place and is assembled from SecuritySettings, so the
 * create screen, the edit screen and anything added later all enforce exactly the
 * same configured policy without copying the rule around.
 *
 * With the shipped defaults the rule returned here is identical to the old
 * literal: min(10), a letter required (always, matching ->letters()), a number
 * required, a symbol required, and no mixed-case requirement.
 */
final class PasswordPolicy
{
    /**
     * The configured Password rule.
     *
     * ->letters() is always applied, because the historical rule required a letter
     * and nothing on the Security tab turns that off. The 'require upper' toggle
     * adds ->mixedCase() on top, which forces both an upper and a lower case
     * letter; it is OFF by default so first deploy keeps today's behaviour.
     */
    public static function rule(): Password
    {
        $rule = Password::min(SecuritySettings::passwordMin())->letters();

        if (SecuritySettings::passwordRequireUpper()) {
            $rule->mixedCase();
        }

        if (SecuritySettings::passwordRequireNumber()) {
            $rule->numbers();
        }

        if (SecuritySettings::passwordRequireSymbol()) {
            $rule->symbols();
        }

        return $rule;
    }
}
