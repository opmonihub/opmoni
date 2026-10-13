<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordHashingTest extends TestCase
{
    public function test_argon2id_unique_salts_and_full_unicode_passwords(): void
    {
        $password = ' '.str_repeat('密碼é', 35).' ';
        $first = Hash::make($password);
        $second = Hash::make($password);
        $this->assertStringStartsWith('$argon2id$', $first);
        $this->assertNotSame($first, $second);
        $this->assertTrue(Hash::check($password, $first));
        $this->assertFalse(Hash::check(trim($password), $first));
        $this->assertFalse(Hash::check(substr($password, 0, 72), $first));
    }
}
