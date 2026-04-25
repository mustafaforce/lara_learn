<?php

namespace App\Domain\Nid\Services;

use App\Domain\Nid\Entities\NidCardInfo;

final class NidTextParser
{
    /**
     * @return array{info: NidCardInfo, warnings: array<int, string>}
     */
    public function parse(string $frontText, string $backText): array
    {
        $combinedText = trim($frontText."\n".$backText);
        $lines = $this->prepareLines($combinedText);

        $info = new NidCardInfo(
            name: [
                'bn' => $this->extractField($lines, ['/^\s*নাম\s*[:ঃ-]?\s*(.*)$/u']),
                'en' => $this->extractField($lines, ['/^\s*name\s*[:ঃ-]?\s*(.*)$/iu']),
            ],
            fatherName: [
                'bn' => $this->extractField($lines, ['/^\s*পিতা[র|\s]*নাম\s*[:ঃ-]?\s*(.*)$/u']),
                'en' => $this->extractField($lines, ['/^\s*father[\s\'’`]*s\s*name\s*[:ঃ-]?\s*(.*)$/iu', '/^\s*father\s*name\s*[:ঃ-]?\s*(.*)$/iu']),
            ],
            motherName: [
                'bn' => $this->extractField($lines, ['/^\s*মাতা[র|\s]*নাম\s*[:ঃ-]?\s*(.*)$/u']),
                'en' => $this->extractField($lines, ['/^\s*mother[\s\'’`]*s\s*name\s*[:ঃ-]?\s*(.*)$/iu', '/^\s*mother\s*name\s*[:ঃ-]?\s*(.*)$/iu']),
            ],
            address: [
                'bn' => $this->extractField($lines, ['/^\s*ঠিকানা\s*[:ঃ-]?\s*(.*)$/u'], allowMultiline: true),
                'en' => $this->extractField($lines, ['/^\s*address\s*[:ঃ-]?\s*(.*)$/iu'], allowMultiline: true),
            ],
            nidNumber: $this->extractNidNumber($combinedText),
            dateOfBirth: $this->extractDate($combinedText, ['date of birth', 'জন্ম তারিখ', 'জন্মতারিখ', 'dob']),
            bloodGroup: $this->extractBloodGroup($combinedText),
            issueDate: $this->extractDate($combinedText, ['date of issue', 'ইস্যু', 'issued']),
        );

        return [
            'info' => $info,
            'warnings' => $this->buildWarnings($info),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function prepareLines(string $text): array
    {
        $text = $this->normalizeDigits($text);
        $text = preg_replace('/\r\n?|\t/u', "\n", $text) ?? $text;
        $rawLines = preg_split('/\n+/u', $text) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $line): string => trim(preg_replace('/\s+/u', ' ', $line) ?? $line),
            $rawLines,
        )));
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<int, string>  $patterns
     */
    private function extractField(array $lines, array $patterns, bool $allowMultiline = false): ?string
    {
        foreach ($lines as $index => $line) {
            foreach ($patterns as $pattern) {
                if (! preg_match($pattern, $line, $matches)) {
                    continue;
                }

                $value = trim($matches[1] ?? '');

                if ($value === '') {
                    $value = $this->collectFollowingLines($lines, $index, $allowMultiline);
                }

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function collectFollowingLines(array $lines, int $fromIndex, bool $allowMultiline): string
    {
        $valueLines = [];
        $maxLines = $allowMultiline ? 3 : 1;

        for ($i = $fromIndex + 1; $i < count($lines) && count($valueLines) < $maxLines; $i++) {
            $candidate = trim($lines[$i]);

            if ($candidate === '' || $this->looksLikeFieldLabel($candidate)) {
                break;
            }

            $valueLines[] = $candidate;

            if (! $allowMultiline) {
                break;
            }
        }

        return trim(implode(', ', $valueLines));
    }

    private function looksLikeFieldLabel(string $line): bool
    {
        return (bool) preg_match('/^(name|father|mother|address|date|dob|id|nid|রক্ত|নাম|পিতার|মাতার|ঠিকানা|জন্ম)/iu', $line);
    }

    private function extractNidNumber(string $text): ?string
    {
        $text = $this->normalizeDigits($text);

        $labeledPatterns = [
            '/(?:national\s*id\s*no\.?|nid\s*(?:number|no\.?)?|id\s*no\.?)\s*[:ঃ-]?\s*([0-9]{10,17})/iu',
            '/(?:জাতীয়\s*পরিচয়পত্র\s*(?:নং|নম্বর)?|এনআইডি\s*(?:নং|নম্বর)?)\s*[:ঃ-]?\s*([0-9]{10,17})/u',
        ];

        foreach ($labeledPatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return $matches[1];
            }
        }

        if (preg_match_all('/\b([0-9]{10,17})\b/u', $text, $matches) && ! empty($matches[1])) {
            usort($matches[1], static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

            return $matches[1][0];
        }

        return null;
    }

    /**
     * @param  array<int, string>  $hints
     */
    private function extractDate(string $text, array $hints): ?string
    {
        $text = $this->normalizeDigits($text);

        foreach (preg_split('/\n+/u', $text) ?: [] as $line) {
            $lineLower = mb_strtolower($line);
            $hasHint = false;

            foreach ($hints as $hint) {
                if (str_contains($lineLower, mb_strtolower($hint))) {
                    $hasHint = true;
                    break;
                }
            }

            if (! $hasHint) {
                continue;
            }

            if (preg_match('/\b([0-9]{1,2}[.\/-][0-9]{1,2}[.\/-][0-9]{2,4})\b/u', $line, $matches)) {
                return str_replace('.', '/', $matches[1]);
            }
        }

        if (preg_match('/\b([0-9]{1,2}[.\/-][0-9]{1,2}[.\/-][0-9]{2,4})\b/u', $text, $matches)) {
            return str_replace('.', '/', $matches[1]);
        }

        return null;
    }

    private function extractBloodGroup(string $text): ?string
    {
        if (preg_match('/\b(AB|A|B|O)\s*([+-])(?![A-Za-z0-9])/i', $this->normalizeDigits($text), $matches)) {
            return strtoupper($matches[1]).$matches[2];
        }

        return null;
    }

    private function normalizeDigits(string $text): string
    {
        return strtr($text, [
            '০' => '0',
            '১' => '1',
            '২' => '2',
            '৩' => '3',
            '৪' => '4',
            '৫' => '5',
            '৬' => '6',
            '৭' => '7',
            '৮' => '8',
            '৯' => '9',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function buildWarnings(NidCardInfo $info): array
    {
        $warnings = [];

        if ($info->nidNumber === null) {
            $warnings[] = 'NID number not detected.';
        }

        if ($info->name['bn'] === null && $info->name['en'] === null) {
            $warnings[] = 'Name not detected.';
        }

        if ($info->dateOfBirth === null) {
            $warnings[] = 'Date of birth not detected.';
        }

        return $warnings;
    }
}
