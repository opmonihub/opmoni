<?php

namespace App\Erp;

use Illuminate\Validation\ValidationException;

final class ErpCatalogPrintGeometry
{
    private const SCALE = 10_000;

    /** @var list<string> */
    private const DIMENSION_FIELDS = [
        'label_width_mm',
        'label_height_mm',
        'paper_width_mm',
        'paper_height_mm',
        'top_margin_mm',
        'left_margin_mm',
        'row_spacing_mm',
        'column_spacing_mm',
    ];

    /** @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    public static function normalize(array $layout): array
    {
        foreach (self::DIMENSION_FIELDS as $field) {
            if (array_key_exists($field, $layout) && $layout[$field] !== null) {
                $layout[$field] = self::normalizeDecimal($layout[$field], $field);
            }
        }

        return $layout;
    }

    /** @param array<string, mixed> $layout */
    public static function assertLayoutFits(array $layout): void
    {
        $labelWidth = self::units($layout['label_width_mm'] ?? null, 'label_width_mm');
        $labelHeight = self::units($layout['label_height_mm'] ?? null, 'label_height_mm');
        $paperWidth = self::units($layout['paper_width_mm'] ?? null, 'paper_width_mm');
        $topMargin = self::units($layout['top_margin_mm'] ?? null, 'top_margin_mm');
        $leftMargin = self::units($layout['left_margin_mm'] ?? null, 'left_margin_mm');
        $rowSpacing = self::units($layout['row_spacing_mm'] ?? null, 'row_spacing_mm');
        $columnSpacing = self::units($layout['column_spacing_mm'] ?? null, 'column_spacing_mm');
        $columns = (int) ($layout['columns_per_row'] ?? 0);
        $isContinuous = (bool) ($layout['is_continuous'] ?? false);
        $paperHeight = $layout['paper_height_mm'] ?? null;
        $labelsPerSheet = $layout['labels_per_sheet'] ?? null;

        self::assertRange('label_width_mm', $layout['label_width_mm'] ?? null, 20, 200);
        self::assertRange('label_height_mm', $layout['label_height_mm'] ?? null, 10, 200);
        self::assertRange('paper_width_mm', $layout['paper_width_mm'] ?? null, 20, 500);
        foreach (['top_margin_mm', 'left_margin_mm', 'row_spacing_mm', 'column_spacing_mm'] as $field) {
            self::assertRange($field, $layout[$field] ?? null, 0, 100);
        }
        if ($paperHeight !== null) {
            self::assertRange('paper_height_mm', $paperHeight, 10, 2000);
        }
        if ($columns < 1 || $columns > 20) {
            throw ValidationException::withMessages(['columns_per_row' => 'O número de colunas está fora dos limites permitidos.']);
        }

        $usedWidth = $leftMargin + ($columns * $labelWidth) + (($columns - 1) * $columnSpacing);
        if ($usedWidth > $paperWidth) {
            throw ValidationException::withMessages([
                'paper_width_mm' => 'A largura da folha não comporta as etiquetas e os espaçamentos informados.',
            ]);
        }

        if ($isContinuous) {
            if ($paperHeight !== null) {
                throw ValidationException::withMessages(['paper_height_mm' => 'Deixe a altura da folha vazia no modo contínuo.']);
            }
            if ($labelsPerSheet !== null) {
                throw ValidationException::withMessages(['labels_per_sheet' => 'Deixe a quantidade por folha vazia no modo contínuo.']);
            }

            return;
        }

        if ($paperHeight === null) {
            throw ValidationException::withMessages(['paper_height_mm' => 'Informe a altura da folha para o modo em folhas.']);
        }
        if ($labelsPerSheet === null || (int) $labelsPerSheet < 1) {
            throw ValidationException::withMessages(['labels_per_sheet' => 'Informe a quantidade de etiquetas por folha.']);
        }
        if ((int) $labelsPerSheet > 250) {
            throw ValidationException::withMessages(['labels_per_sheet' => 'A quantidade por folha não pode ultrapassar 250.']);
        }

        $rows = intdiv((int) $labelsPerSheet + $columns - 1, $columns);
        $usedHeight = $topMargin + ($rows * $labelHeight) + (($rows - 1) * $rowSpacing);
        if ($usedHeight > self::units($paperHeight, 'paper_height_mm')) {
            throw ValidationException::withMessages([
                'paper_height_mm' => 'A altura da folha não comporta as etiquetas e os espaçamentos informados.',
            ]);
        }
    }

    public static function assertRange(string $attribute, mixed $value, int $minimumMm, int $maximumMm): void
    {
        $units = self::units($value, $attribute);
        if ($units < $minimumMm * self::SCALE || $units > $maximumMm * self::SCALE) {
            throw ValidationException::withMessages([$attribute => 'O valor está fora dos limites permitidos.']);
        }
    }

    /** @param array<string, mixed> $layout */
    public static function continuousOutputHeight(array $layout, int $copies): ?string
    {
        if (! (bool) ($layout['is_continuous'] ?? false)) {
            return null;
        }

        $columns = (int) ($layout['columns_per_row'] ?? 0);
        $rows = intdiv($copies + $columns - 1, $columns);
        $height = self::units($layout['top_margin_mm'] ?? null, 'top_margin_mm')
            + ($rows * self::units($layout['label_height_mm'] ?? null, 'label_height_mm'))
            + (max(0, $rows - 1) * self::units($layout['row_spacing_mm'] ?? null, 'row_spacing_mm'));

        if ($height > 20_000_000) {
            throw ValidationException::withMessages(['layout_id' => 'A altura contínua ultrapassa o limite de 2.000 mm.']);
        }

        return self::decimal($height);
    }

    private static function normalizeDecimal(mixed $value, string $attribute): string
    {
        if (! is_string($value) || preg_match('/\A\d+(?:\.\d{1,4})?\z/D', $value) !== 1) {
            throw ValidationException::withMessages([$attribute => 'Informe um decimal como texto com até quatro casas decimais.']);
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ltrim($whole, '0') === '' ? '0.'.str_pad($fraction, 4, '0') : ltrim($whole, '0').'.'.str_pad($fraction, 4, '0');
    }

    private static function units(mixed $value, string $attribute): int
    {
        $normalized = self::normalizeDecimal($value, $attribute);
        [$whole, $fraction] = explode('.', $normalized, 2);

        return ((int) $whole * self::SCALE) + (int) $fraction;
    }

    private static function decimal(int $units): string
    {
        return intdiv($units, self::SCALE).'.'.str_pad((string) ($units % self::SCALE), 4, '0', STR_PAD_LEFT);
    }
}
