<?php

namespace App\Modules\Profiles\Services;

use App\Modules\Profiles\Models\Cv;
use ZipArchive;

/**
 * Decides what an uploaded file really is by reading its content.
 *
 * The file name and the Content-Type header come from the client and can say anything:
 * renaming virus.exe to cv.pdf must not get it stored as a PDF.
 */
final class CvFileInspector
{
    /** @return string|null `Cv::PDF`, `Cv::DOCX`, or null when the file is neither. */
    public function detect(string $path): ?string
    {
        $start = (string) file_get_contents($path, length: 5);

        if ($start === '%PDF-') {
            return Cv::PDF;
        }

        // A .docx is a ZIP archive, and so are .xlsx, .jar and many others. All ZIPs start
        // with "PK", so we also look inside for the part every Word document must have.
        if (str_starts_with($start, "PK\x03\x04") && $this->zipContains($path, 'word/document.xml')) {
            return Cv::DOCX;
        }

        return null;
    }

    public function extensionFor(string $contentType): string
    {
        return $contentType === Cv::PDF ? 'pdf' : 'docx';
    }

    private function zipContains(string $path, string $entry): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        $found = $zip->locateName($entry) !== false;
        $zip->close();

        return $found;
    }
}
