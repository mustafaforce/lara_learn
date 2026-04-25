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
        $frontLines = $this->prepareLines($frontText);
        $backLines = $this->prepareLines($backText);
        $lines = $this->prepareLines($combinedText);

        $info = new NidCardInfo(
            name: [
                'bn' => $this->extractField($lines, ['/নাম\s*[:ঃ-]?\s*(.*)$/u']),
                'en' => $this->extractField($lines, ['/\bname\b\s*[:ঃ-]?\s*(.*)$/iu']),
            ],
            fatherName: [
                'bn' => $this->extractField($lines, ['/পিতার?\s*(?:নাম)?\s*[:ঃ-]?\s*(.*)$/u']),
                'en' => $this->extractField($lines, ['/\bfather(?:\s*[\x{2019}\'`]?s)?\s*(?:name)?\s*[:ঃ-]?\s*(.*)$/iu']),
            ],
            motherName: [
                'bn' => $this->extractField($lines, ['/মাতার?\s*(?:নাম)?\s*[:ঃ-]?\s*(.*)$/u']),
                'en' => $this->extractField($lines, ['/\bmother(?:\s*[\x{2019}\'`]?s)?\s*(?:name)?\s*[:ঃ-]?\s*(.*)$/iu']),
            ],
            address: [
                'bn' => $this->extractField($backLines, ['/ঠিকানা\s*[:ঃ-]?\s*(.*)$/u'], allowMultiline: true)
                    ?? $this->extractField($lines, ['/ঠিকানা\s*[:ঃ-]?\s*(.*)$/u'], allowMultiline: true),
                'en' => $this->extractField($backLines, ['/\baddress\b\s*[:ঃ-]?\s*(.*)$/iu'], allowMultiline: true)
                    ?? $this->extractField($lines, ['/\baddress\b\s*[:ঃ-]?\s*(.*)$/iu'], allowMultiline: true),
            ],
            nidNumber: $this->extractNidNumber($combinedText),
            dateOfBirth: $this->extractDate($frontText, ['date of birth', 'জন্ম তারিখ', 'জন্মতারিখ', 'dob'], false),
            bloodGroup: $this->extractBloodGroup($combinedText),
            issueDate: $this->extractDate($backText, ['date of issue', 'ইস্যু', 'issued', 'প্রদানের তারিখ'], false),
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

                $value = $this->cleanExtractedValue($value);

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

    private function cleanExtractedValue(string $value): string
    {
        $value = trim(preg_replace('/^[\s:ঃ\-|>~`]+/u', '', $value) ?? $value);
        $value = trim(preg_replace('/\s{2,}/u', ' ', $value) ?? $value);
        $value = trim($value, " \t\n\r\0\x0B:|>~`");

        if ($value === '' || mb_strlen($value) < 2) {
            return '';
        }

        if (preg_match('/^[0-9]+$/u', $value)) {
            return '';
        }

        return $value;
    }

    private function looksLikeFieldLabel(string $line): bool
    {
        return (bool) preg_match('/^(name|father|mother|address|date|dob|id|nid|রক্ত|নাম|পিতার|মাতার|ঠিকানা|জন্ম)/iu', $line);
    }

    private function extractNidNumber(string $text): ?string
    {
        $text = $this->normalizeDigits($text);
        $normalizedForNumber = preg_replace('/(?<=\d)[ \t]+(?=\d)/u', '', $text) ?? $text;

        $labeledPatterns = [
            '/(?:national\s*id\s*no\.?|nid\s*(?:number|no\.?)?|id\s*no\.?)\s*[:ঃ-]?\s*([0-9]{10,17})/iu',
            '/(?:জাতীয়\s*পরিচয়পত্র\s*(?:নং|নম্বর)?|এনআইডি\s*(?:নং|নম্বর)?)\s*[:ঃ-]?\s*([0-9]{10,17})/u',
            '/\bid\s*no\b\s*[:ঃ-]?\s*([0-9]{10,17})/iu',
        ];

        foreach ($labeledPatterns as $pattern) {
            if (preg_match($pattern, $normalizedForNumber, $matches)) {
                return $matches[1];
            }
        }

        if (preg_match_all('/\b([0-9]{10,17})\b/u', $normalizedForNumber, $matches) && ! empty($matches[1])) {
            usort($matches[1], static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

            return $matches[1][0];
        }

        return null;
    }

    /**
     * @param  array<int, string>  $hints
     */
    private function extractDate(string $text, array $hints, bool $allowAnyDateFallback = true): ?string
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

            $parsed = $this->parseDateFromLine($line);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        if ($allowAnyDateFallback) {
            foreach (preg_split('/\n+/u', $text) ?: [] as $line) {
                $parsed = $this->parseDateFromLine($line);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        return null;
    }

    private function parseDateFromLine(string $line): ?string
    {
        if (preg_match('/\b([0-9]{1,2})[.\/-]([0-9]{1,2})[.\/-]([0-9]{2,4})\b/u', $line, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $this->normalizeYear($matches[3]);

            return "{$day}/{$month}/{$year}";
        }

        if (preg_match('/\b([0-9]{1,2})\s*([A-Za-z]{3,9})\s*([0-9]{2,4})\b/u', $line, $matches)) {
            $monthMap = [
                'jan' => '01', 'january' => '01',
                'feb' => '02', 'february' => '02',
                'mar' => '03', 'march' => '03',
                'apr' => '04', 'april' => '04',
                'may' => '05',
                'jun' => '06', 'june' => '06',
                'jul' => '07', 'july' => '07',
                'aug' => '08', 'august' => '08',
                'sep' => '09', 'sept' => '09', 'september' => '09',
                'oct' => '10', 'october' => '10',
                'nov' => '11', 'november' => '11',
                'dec' => '12', 'december' => '12',
            ];

            $monthKey = strtolower($matches[2]);
            if (! isset($monthMap[$monthKey])) {
                return null;
            }

            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $year = $this->normalizeYear($matches[3]);

            return "{$day}/{$monthMap[$monthKey]}/{$year}";
        }

        return null;
    }

    private function normalizeYear(string $year): string
    {
        $year = trim($year);

        if (strlen($year) === 2) {
            return ((int) $year > 30 ? '19' : '20').$year;
        }

        return str_pad($year, 4, '0', STR_PAD_LEFT);
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
