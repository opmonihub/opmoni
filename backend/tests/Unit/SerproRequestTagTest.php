<?php

namespace Tests\Unit;

use App\Services\SerproRequestTag;
use PHPUnit\Framework\TestCase;

class SerproRequestTagTest extends TestCase
{
    public function test_it_builds_a_32_character_tag_for_two_companies(): void
    {
        $tag = (new SerproRequestTag)->build('33683111000107', '33683111000875', 1);

        $this->assertSame('23368311100010723368311100087501', $tag);
        $this->assertSame(32, strlen($tag));
    }

    public function test_it_marks_an_individual_as_type_one(): void
    {
        $tag = (new SerproRequestTag)->build('33683111000107', '12345678901', 1);

        $this->assertSame(1, (int) $tag[15]);
    }

    public function test_it_pads_the_service_sequence_to_two_digits(): void
    {
        $this->assertSame('12', substr((new SerproRequestTag)->build('33683111000107', '33683111000875', 12), 30));
        $this->assertSame('05', substr((new SerproRequestTag)->build('33683111000107', '33683111000875', 5), 30));
    }

    public function test_it_accepts_an_alphanumeric_document(): void
    {
        $tag = (new SerproRequestTag)->build('E0000161000121', 'A0000177000110', 1);

        $this->assertSame(32, strlen($tag));
        $this->assertStringStartsWith('2E0000161000121', $tag);
    }
}
