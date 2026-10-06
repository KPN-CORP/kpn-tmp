<?php

namespace Tests\Unit;

use App\Http\Requests\SubmitIdpResultRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Filing a result: the evidence must be a link an approver can open, and a
 * pasted link without its scheme is completed rather than rejected.
 */
class SubmitIdpResultRequestTest extends TestCase
{
    /** Run the request's own normalization + rules over a payload, without HTTP or a database. */
    private function validate(array $payload): \Illuminate\Validation\Validator
    {
        $request = SubmitIdpResultRequest::create('/', 'POST', $payload);
        $request->setContainer($this->app);

        $prepare = new \ReflectionMethod($request, 'prepareForValidation');
        $prepare->invoke($request);

        return Validator::make($request->all(), $request->rules(), $request->messages());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'realization_date' => '2026-06-01',
            'achievement' => '10',
            'result_evidence' => 'https://drive.google.com/file/d/abc',
        ], $overrides);
    }

    /** @return array<string, array{string, string|null}> typed => stored (null = rejected) */
    public static function evidence(): array
    {
        return [
            'full link' => ['https://drive.google.com/file/d/abc', 'https://drive.google.com/file/d/abc'],
            'no scheme' => ['drive.google.com/file/d/abc', 'https://drive.google.com/file/d/abc'],
            'bare host' => ['www.gdrive.com', 'https://www.gdrive.com'],
            'padded' => ['  example.com/x  ', 'https://example.com/x'],
            // A path on the office network is evidence too, stored as typed.
            'UNC path' => ['\\\\fileserver\\HR\\Training\\certificate.pdf', '\\\\fileserver\\HR\\Training\\certificate.pdf'],
            'UNC path with spaces' => ['\\\\fs01\\HR Share\\Cert 2026.pdf', '\\\\fs01\\HR Share\\Cert 2026.pdf'],
            'UNC, forward slashes' => ['//fs01/HR/cert.pdf', '//fs01/HR/cert.pdf'],
            'mapped drive' => ['S:\\HR\\Training\\cert.pdf', 'S:\\HR\\Training\\cert.pdf'],
            'file link' => ['file://fs01/HR/cert.pdf', 'file://fs01/HR/cert.pdf'],
            'server alone, no share' => ['\\\\fileserver', null],
            'a sentence' => ['Certificate of completion attached', null],
            'ftp' => ['ftp://files.example.com/x', null],
            'javascript' => ['javascript:alert(1)', null],
        ];
    }

    #[DataProvider('evidence')]
    public function test_evidence_must_be_a_link_or_a_network_path(string $typed, ?string $stored): void
    {
        $validator = $this->validate($this->payload(['result_evidence' => $typed]));

        if ($stored === null) {
            $this->assertTrue($validator->errors()->has('result_evidence'), "'{$typed}' should be rejected");

            return;
        }

        $this->assertFalse($validator->errors()->has('result_evidence'), "'{$typed}' should be accepted");
        $this->assertSame($stored, $validator->getData()['result_evidence']);
    }

    public function test_a_blank_achievement_is_reported_as_missing_not_as_not_a_number(): void
    {
        foreach (['', '   '] as $blank) {
            $errors = $this->validate($this->payload(['achievement' => $blank]))->errors();

            $this->assertSame('Enter what was actually achieved.', $errors->first('achievement'));
        }
    }

    public function test_zero_is_an_achievement_and_negatives_are_not(): void
    {
        $this->assertFalse($this->validate($this->payload(['achievement' => '0']))->errors()->has('achievement'));
        $this->assertTrue($this->validate($this->payload(['achievement' => '-1']))->errors()->has('achievement'));
    }
}
