<?php

namespace Tests\Feature\Admin;

use App\Models\EventCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A paleta fixa de cores das categorias: o badge usa a cor como texto sobre um fundo suave
 * (12 % da cor sobre branco), por isso cada cor tem de cumprir WCAG AA nas duas situações
 * e não pode confundir-se com o vermelho de aviso da barra de ocupação.
 */
class EventCategoryPaletteConfigTest extends TestCase
{
    use RefreshDatabase;

    private const PALETTE = [
        '#0D1F35' => 'Azul-marinho',
        '#1E449A' => 'Azul-cobalto',
        '#2A6E9E' => 'Azul-aço',
        '#0A4251' => 'Azul-petróleo',
        '#1C747E' => 'Turquesa-escuro',
        '#0B4D3F' => 'Verde-petróleo',
        '#0E784F' => 'Verde-esmeralda',
        '#2B4A12' => 'Verde-floresta',
        '#53731A' => 'Verde-musgo',
        '#5E6013' => 'Verde-azeitona',
        '#7A6238' => 'Ouro-velho',
        '#94591A' => 'Bronze',
        '#5A3A14' => 'Castanho',
        '#3A2E1C' => 'Café',
        '#38207E' => 'Índigo',
        '#6548B8' => 'Violeta',
        '#763B82' => 'Púrpura',
        '#686693' => 'Lilás-acinzentado',
        '#5B6670' => 'Ardósia',
        '#383B41' => 'Grafite',
    ];

    private const WHITE = [255, 255, 255];

    private const MINIMUM_CONTRAST = 4.5;

    /**
     * @return array<string, string>
     */
    private function palette(): array
    {
        return config('event_categories.palette', []);
    }

    /**
     * @return array{int, int, int}
     */
    private function rgb(string $hex): array
    {
        return array_map('hexdec', str_split(substr($hex, 1), 2));
    }

    /**
     * @param  array{int, int, int}  $color
     * @return array{int, int, int}
     */
    private function softBackground(array $color): array
    {
        return array_map(fn (int $channel): int => (int) round($channel * 0.12 + 255 * 0.88), $color);
    }

    /**
     * @param  array{int, int, int}  $color
     */
    private function luminance(array $color): float
    {
        [$red, $green, $blue] = array_map(function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $color);

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }

    /**
     * @param  array{int, int, int}  $first
     * @param  array{int, int, int}  $second
     */
    private function contrast(array $first, array $second): float
    {
        $luminances = [$this->luminance($first), $this->luminance($second)];

        return (max($luminances) + 0.05) / (min($luminances) + 0.05);
    }

    /**
     * @param  array{int, int, int}  $color
     */
    private function hue(array $color): float
    {
        [$red, $green, $blue] = $color;
        $max = max($color);
        $delta = $max - min($color);

        if ($delta === 0) {
            return 0.0;
        }

        $hue = match ($max) {
            $red => 60 * fmod(($green - $blue) / $delta, 6),
            $green => 60 * (($blue - $red) / $delta + 2),
            default => 60 * (($red - $green) / $delta + 4),
        };

        return $hue < 0 ? $hue + 360 : $hue;
    }

    #[Test]
    public function palette_has_the_twenty_agreed_colors_in_order(): void
    {
        $this->assertSame(self::PALETTE, $this->palette());
    }

    #[Test]
    public function palette_colors_are_uppercase_hex_without_repetitions(): void
    {
        $colors = array_keys($this->palette());

        $this->assertCount(20, $colors);
        $this->assertSame($colors, array_values(array_unique($colors)));

        foreach ($colors as $color) {
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $color);
        }
    }

    #[Test]
    public function every_color_meets_aa_contrast_on_white_and_on_its_soft_background(): void
    {
        $this->assertNotEmpty($this->palette());

        foreach (array_keys($this->palette()) as $hex) {
            $color = $this->rgb($hex);

            $this->assertGreaterThanOrEqual(self::MINIMUM_CONTRAST, $this->contrast($color, self::WHITE), "{$hex} sobre branco.");
            $this->assertGreaterThanOrEqual(self::MINIMUM_CONTRAST, $this->contrast($color, $this->softBackground($color)), "{$hex} sobre o fundo suave.");
        }
    }

    #[Test]
    public function no_color_falls_in_the_red_hue_zone(): void
    {
        $this->assertNotEmpty($this->palette());

        foreach (array_keys($this->palette()) as $hex) {
            $hue = $this->hue($this->rgb($hex));

            $this->assertGreaterThanOrEqual(30, $hue, $hex);
            $this->assertLessThanOrEqual(290, $hue, $hex);
        }
    }

    #[Test]
    public function seeded_category_colors_belong_to_the_palette(): void
    {
        foreach (EventCategory::pluck('color', 'name') as $name => $color) {
            $this->assertArrayHasKey($color, $this->palette(), $name);
        }
    }
}
