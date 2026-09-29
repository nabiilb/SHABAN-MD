<?php

namespace Tests\Feature;

use App\Models\CaseAttachment;
use App\Models\DentalCase;
use App\Support\CaseFiles;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AttachmentsTest extends TestCase
{
    private static function png(): string
    {
        return hex2bin('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d4944415478da6300010000050001');
    }

    private const PDF = "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF";

    private const STL = "solid cube\nendsolid cube\n";

    private static function file(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /** @return list<string> relative paths of stored case files */
    private static function stored(): array
    {
        return CaseFiles::disk()->allFiles();
    }

    public function test_uploads_lists_downloads_byte_for_byte_and_deletes(): void
    {
        $reception = $this->as('reception');
        $c = DentalCase::where('status', 'in_production')->first();
        $res = $reception->upload("/cases/{$c->id}/attachments", self::file('intraoral photo.png', self::png()), ['category' => 'photo']);
        $res->assertCreated()->assertJson(['name' => 'intraoral photo.png', 'extension' => 'png', 'mimeType' => 'image/png', 'size' => strlen(self::png()), 'category' => 'photo', 'uploadedByName' => 'Sagal Warsame']);
        $this->assertArrayNotHasKey('storageKey', $res->json());
        $key = CaseAttachment::find($res->json('id'))->storage_key;
        $this->assertStringNotContainsString('intraoral', $key); // user file names never reach the filesystem

        $this->assertContains($res->json('id'), array_column($reception->get("/cases/{$c->id}")->json('attachments'), 'id'));

        $dl = $reception->get("/cases/{$c->id}/attachments/{$res->json('id')}/download");
        $dl->assertOk();
        $this->assertSame(self::png(), $dl->streamedContent());
        $this->assertSame('image/png', $dl->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/attachment; filename="intraoral photo.png"/', $dl->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $dl->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $dl->headers->get('Cache-Control'));

        $this->assertContains($key, self::stored());
        $reception->delete("/cases/{$c->id}/attachments/{$res->json('id')}")->assertNoContent();
        $this->assertNotContains($key, self::stored());
        $reception->get("/cases/{$c->id}/attachments/{$res->json('id')}/download")->assertStatus(404);
    }

    public function test_accepts_pdf_and_stl_and_infers_the_category(): void
    {
        $reception = $this->as('reception');
        $c = DentalCase::where('status', 'received')->first();
        $this->assertSame('prescription', $reception->upload("/cases/{$c->id}/attachments", self::file('rx.pdf', self::PDF))->json('category'));
        $stl = $reception->upload("/cases/{$c->id}/attachments", self::file('upper.stl', self::STL));
        $stl->assertCreated()->assertJson(['category' => 'scan', 'extension' => 'stl']);
        // Binary STL (80-byte header + triangle count) is accepted too.
        $binary = str_repeat("\0", 80).pack('V', 1).str_repeat("\0", 50);
        $reception->upload("/cases/{$c->id}/attachments", self::file('lower.stl', $binary))->assertCreated()->assertJson(['category' => 'scan']);
    }

    public function test_rejects_disallowed_types_spoofed_content_oversize_and_missing_files(): void
    {
        $reception = $this->as('reception');
        $c = DentalCase::where('status', 'received')->first();
        $url = "/cases/{$c->id}/attachments";
        $before = count(self::stored());
        $reception->upload($url, self::file('tool.exe', "MZ\x90\x00"))->assertStatus(422);
        $reception->upload($url, self::file('shell.php', '<?php echo 1;'))->assertStatus(422);
        $spoofed = $reception->upload($url, self::file('photo.png', '<html><script>alert(1)</script></html>'));
        $spoofed->assertStatus(422);
        $this->assertMatchesRegularExpression('/not accepted|does not match/', $spoofed->json('errors.file.0'));
        $this->assertMatchesRegularExpression('/does not match/', $reception->upload($url, self::file('scan.pdf', self::png()))->json('errors.file.0'));
        $this->assertMatchesRegularExpression('/not accepted/', $reception->upload($url, self::file('scan.stl', '<html>'))->json('errors.file.0'));
        // .stl must be an ASCII ("solid …") or a well-formed binary STL.
        $this->assertMatchesRegularExpression('/does not match/', $reception->upload($url, self::file('scan.stl', self::png()))->json('errors.file.0'));
        $this->assertMatchesRegularExpression('/does not match/', $reception->upload($url, self::file('scan.stl', str_repeat("\0", 80).pack('V', 1000).'short'))->json('errors.file.0'));
        $reception->upload($url, null, ['category' => 'photo'])->assertStatus(422);
        $this->assertArrayHasKey('category', $reception->upload($url, self::file('a.png', self::png()), ['category' => 'selfie'])->json('errors'));
        // MAX_UPLOAD_MB is 1 in tests.
        $big = $reception->upload($url, self::file('big.stl', 'solid x'.str_repeat(' ', 1024 * 1024 + 10)));
        $big->assertStatus(422);
        $this->assertMatchesRegularExpression('/MB/', $big->json('errors.file.0'));
        $this->assertCount($before, self::stored()); // nothing rejected was kept
    }

    public function test_scope_and_ownership(): void
    {
        $client = $this->as('client');
        $other = DentalCase::where('clinic_id', '!=', 'cln_smile')->first();
        $client->upload("/cases/{$other->id}/attachments", self::file('x.png', self::png()))->assertStatus(404);

        $reception = $this->as('reception');
        $tech = $this->as('technician');
        $own = DentalCase::where('technician_id', 'tec_fatima')->where('status', 'in_production')->first();
        $up = $reception->upload("/cases/{$own->id}/attachments", self::file('r.png', self::png()));
        $up->assertCreated();
        $tech->delete("/cases/{$own->id}/attachments/{$up->json('id')}")->assertStatus(403);
        $this->as('admin')->delete("/cases/{$own->id}/attachments/{$up->json('id')}")->assertNoContent();
    }

    public function test_files_are_only_reachable_through_the_authorised_download(): void
    {
        $reception = $this->as('reception');
        $c = DentalCase::where('clinic_id', 'cln_horizon')->where('status', 'in_production')->first() ?? DentalCase::where('clinic_id', '!=', 'cln_smile')->first();
        $up = $reception->upload("/cases/{$c->id}/attachments", self::file('scan.stl', self::STL))->assertCreated();
        $path = "/cases/{$c->id}/attachments/{$up->json('id')}/download";

        $this->client()->get($path)->assertStatus(401);                  // anonymous
        $this->as('client')->get($path)->assertStatus(404);               // another clinic's case
        $this->as('technician2')->get($path)->assertStatus(404);          // not their case
        // An attachment id from one case cannot be fetched through another case.
        $mine = DentalCase::where('clinic_id', 'cln_smile')->first();
        $reception->get("/cases/{$mine->id}/attachments/{$up->json('id')}/download")->assertStatus(404);
        $reception->get($path)->assertOk();

        // The storage root is outside the web root.
        $this->assertStringStartsNotWith(realpath(public_path()), CaseFiles::disk()->path(''));
    }
}
