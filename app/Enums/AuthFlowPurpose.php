<?php

namespace App\Enums;

enum AuthFlowPurpose: string
{
    case EmailVerification = 'email_verification';
    case PasswordSetup = 'password_setup';
    case PasswordReset = 'password_reset';
}
