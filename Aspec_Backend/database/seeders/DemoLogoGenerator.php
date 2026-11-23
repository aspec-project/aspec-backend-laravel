<?php

namespace Database\Seeders;

use GdImage;
use Illuminate\Support\Str;

/**
 * Logótipos de demonstração (PNG 400×400) desenhados com GD: monograma de 1 a 3 letras e o nome
 * curto da empresa, numa de quatro composições (círculo, quadrado arredondado, barra ou só letras).
 * Usa a fonte livre Poppins (SIL OFL, em database/seeders/demo/fonts); sem FreeType desenha só as
 * iniciais com a fonte embutida do GD. O mesmo nome e variante dão sempre o mesmo ficheiro.
 */
class DemoLogoGenerator
{
    /** Tamanho do logótipo (quadrado), em píxeis. */
    public const SIZE = 400;

    /** Cores de fundo (RGB), escuras o suficiente para texto branco. */
    private const PALETTE = [
        [13, 31, 53],
        [49, 90, 121],
        [138, 112, 67],
        [46, 107, 79],
        [122, 62, 72],
        [75, 78, 140],
        [160, 82, 45],
        [30, 116, 126],
        [94, 60, 110],
        [52, 73, 94],
    ];

    /**
     * Indica se o PHP consegue desenhar texto com fontes TrueType (GD com FreeType e a fonte no repositório).
     */
    public function supportsTrueType(): bool
    {
        return function_exists('imagettftext')
            && (gd_info()['FreeType Support'] ?? false)
            && is_file($this->fontPath());
    }

    /**
     * Gera o logótipo da empresa. A variante escolhe a composição e mexe na cor, para empresas
     * parecidas não ficarem iguais.
     *
     * @return string Conteúdo PNG (400×400).
     */
    public function generate(string $businessName, int $variant): string
    {
        $color = self::PALETTE[(crc32($businessName) + $variant) % count(self::PALETTE)];
        $monogram = $this->monogram($businessName);

        $image = $this->supportsTrueType()
            ? $this->drawTrueType($businessName, $monogram, $variant % 4, $color)
            : $this->drawSimple($monogram, $color);

        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    /**
     * Logótipo com a fonte TrueType numa das quatro composições.
     *
     * @param  array{int, int, int}  $color
     */
    private function drawTrueType(string $businessName, string $monogram, int $style, array $color): GdImage
    {
        $image = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($image, true);
        $white = imagecolorallocate($image, 255, 255, 255);
        $main = imagecolorallocate($image, ...$color);
        $light = imagecolorallocate($image, ...array_map(fn (int $channel) => (int) round($channel + (255 - $channel) * 0.88), $color));
        $name = $this->shortName($businessName);

        match ($style) {
            0 => $this->circleStyle($image, $monogram, $name, $white, $main),
            1 => $this->roundedSquareStyle($image, $monogram, $name, $white, $main),
            2 => $this->barStyle($image, $monogram, $name, $white, $main),
            default => $this->lettersStyle($image, $monogram, $name, $light, $main),
        };

        return $image;
    }

    private function circleStyle(GdImage $image, string $monogram, string $name, int $white, int $main): void
    {
        imagefill($image, 0, 0, $white);
        imagefilledellipse($image, 200, 160, 230, 230, $main);
        $this->centeredText($image, $monogram, 200, 160, 190, 95, $white);
        $this->centeredText($image, $name, 200, 330, 340, 30, $main);
    }

    private function roundedSquareStyle(GdImage $image, string $monogram, string $name, int $white, int $main): void
    {
        imagefill($image, 0, 0, $white);
        $this->roundedRectangle($image, 20, 20, 380, 380, 60, $main);
        $this->centeredText($image, $monogram, 200, 170, 280, 120, $white);
        $this->centeredText($image, $name, 200, 310, 300, 28, $white);
    }

    private function barStyle(GdImage $image, string $monogram, string $name, int $white, int $main): void
    {
        imagefill($image, 0, 0, $white);
        $this->centeredText($image, $monogram, 200, 150, 300, 130, $main);
        imagefilledrectangle($image, 60, 245, 340, 255, $main);
        $this->centeredText($image, $name, 200, 310, 330, 30, $main);
    }

    private function lettersStyle(GdImage $image, string $monogram, string $name, int $light, int $main): void
    {
        imagefill($image, 0, 0, $light);
        $this->centeredText($image, $monogram, 200, 175, 320, 150, $main);
        $this->centeredText($image, Str::upper($name), 200, 315, 320, 24, $main);
    }

    /**
     * Sem FreeType: fundo com a cor e as iniciais ampliadas a partir da fonte embutida do GD (9×15 px).
     *
     * @param  array{int, int, int}  $color
     */
    private function drawSimple(string $monogram, array $color): GdImage
    {
        $image = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$color));

        $font = 5;
        $width = imagefontwidth($font) * strlen($monogram);
        $height = imagefontheight($font);
        $scale = min(12, intdiv(300, $width));

        $small = imagecreatetruecolor($width, $height);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagestring($small, $font, 0, 0, $monogram, imagecolorallocate($small, 255, 255, 255));

        imagecopyresized(
            $image, $small,
            200 - intdiv($width * $scale, 2), 200 - intdiv($height * $scale, 2),
            0, 0,
            $width * $scale, $height * $scale,
            $width, $height
        );

        return $image;
    }

    /**
     * Escreve o texto centrado em ($centerX, $centerY), com o maior tamanho até $maxSize que caiba em $maxWidth.
     */
    private function centeredText(GdImage $image, string $text, int $centerX, int $centerY, int $maxWidth, int $maxSize, int $color): void
    {
        $font = $this->fontPath();
        $size = $maxSize;

        do {
            $box = imagettfbbox($size, 0, $font, $text);
            $width = $box[2] - $box[0];
            $size -= 2;
        } while ($width > $maxWidth && $size > 8);

        $size += 2;
        $height = $box[1] - $box[7];

        imagettftext($image, $size, 0, $centerX - intdiv($width, 2) - $box[0], $centerY + intdiv($height, 2) - $box[1], $color, $font, $text);
    }

    private function roundedRectangle(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);

        foreach ([[$x1 + $radius, $y1 + $radius], [$x2 - $radius, $y1 + $radius], [$x1 + $radius, $y2 - $radius], [$x2 - $radius, $y2 - $radius]] as [$x, $y]) {
            imagefilledellipse($image, $x, $y, $radius * 2, $radius * 2, $color);
        }
    }

    /**
     * Iniciais das palavras com mais de duas letras (até 3), sem acentos: "Carvalho & Filhos Construções" → "CFC".
     * Uma só palavra dá as duas primeiras letras.
     */
    private function monogram(string $businessName): string
    {
        $words = Str::of(Str::ascii($businessName))
            ->replaceMatches('/[^A-Za-z ]/', ' ')
            ->explode(' ')
            ->filter(fn (string $word) => strlen($word) > 2)
            ->values();

        if ($words->count() === 1) {
            return strtoupper(substr($words->first(), 0, 2));
        }

        return $words->take(3)->map(fn (string $word) => strtoupper($word[0]))->implode('') ?: 'A';
    }

    /**
     * Nome curto para o logótipo: as primeiras palavras até cerca de 18 carateres.
     */
    private function shortName(string $businessName): string
    {
        $short = '';

        foreach (explode(' ', $businessName) as $word) {
            $candidate = trim("{$short} {$word}");

            if ($short !== '' && mb_strlen($candidate) > 18) {
                break;
            }

            $short = $candidate;
        }

        return rtrim($short, ' &,-');
    }

    private function fontPath(): string
    {
        return database_path('seeders/demo/fonts/Poppins-SemiBold.ttf');
    }
}
