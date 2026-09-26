<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Normalizacao de texto usada em tres lugares:
 *  - comparar palpite com resposta (regra 55)
 *  - garantir resposta unica no banco (regra 49)
 *  - bloquear dicas que entreguem a resposta (regra 43)
 */
final class Normalizador
{
    /** Palavras que nao contam como "parte da resposta" no filtro de vazamento. */
    private const PALAVRAS_VAZIAS = [
        'a', 'as', 'o', 'os', 'um', 'uma', 'uns', 'umas',
        'de', 'da', 'do', 'das', 'dos', 'd', 'e', 'em', 'na', 'no', 'nas', 'nos',
        'por', 'para', 'com', 'sem', 'sob', 'sobre', 'ao', 'aos', 'a', 'the', 'of', 'and',
        'ii', 'iii', 'iv', 'jr', 'filho', 'neto', 'santo', 'santa', 'sao',
    ];

    private const MAPA_ACENTOS = [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','ā'=>'a','ă'=>'a','ą'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','ē'=>'e','ĕ'=>'e','ė'=>'e','ę'=>'e','ě'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ī'=>'i','į'=>'i','ı'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ō'=>'o','ő'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ū'=>'u','ů'=>'u','ű'=>'u',
        'ç'=>'c','ć'=>'c','č'=>'c','ñ'=>'n','ń'=>'n','ň'=>'n',
        'ý'=>'y','ÿ'=>'y','š'=>'s','ś'=>'s','ş'=>'s','ž'=>'z','ź'=>'z','ż'=>'z',
        'ł'=>'l','đ'=>'d','ğ'=>'g','ř'=>'r','ť'=>'t','ď'=>'d',
        'æ'=>'ae','œ'=>'oe','ß'=>'ss',
    ];

    /** minuscula + sem acento + so letras/numeros/espaco + espaco unico. */
    public static function normalizar(string $texto): string
    {
        $texto = self::semAcento($texto);
        $texto = preg_replace('/[^a-z0-9\s]/u', ' ', $texto) ?? '';
        $texto = preg_replace('/\s+/u', ' ', $texto) ?? '';

        return trim($texto);
    }

    public static function semAcento(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');

        if (class_exists(\Transliterator::class)) {
            $transliterador = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($transliterador !== null) {
                $convertido = $transliterador->transliterate($texto);
                if (is_string($convertido)) {
                    $texto = $convertido;
                }
            }
        }

        return strtr($texto, self::MAPA_ACENTOS);
    }

    /** Versao compacta, sem espacos: "joao gilberto" -> "joaogilberto". */
    public static function compactar(string $texto): string
    {
        return str_replace(' ', '', self::normalizar($texto));
    }

    /**
     * Palavras significativas de um texto (descarta artigos, preposicoes
     * e palavras com menos de 4 letras).
     *
     * @return array<int,string>
     */
    public static function palavrasSignificativas(string $texto, int $tamanhoMinimo = 4): array
    {
        $palavras = explode(' ', self::normalizar($texto));
        $saida = [];

        foreach ($palavras as $palavra) {
            if ($palavra === '' || in_array($palavra, self::PALAVRAS_VAZIAS, true)) {
                continue;
            }
            if (mb_strlen($palavra) < $tamanhoMinimo) {
                continue;
            }
            $saida[$palavra] = $palavra;
        }

        return array_values($saida);
    }

    /** Distancia de Levenshtein normalizada: 1.0 = identico, 0.0 = nada a ver. */
    public static function semelhanca(string $a, string $b): float
    {
        $a = self::normalizar($a);
        $b = self::normalizar($b);

        if ($a === '' && $b === '') {
            return 1.0;
        }
        if ($a === $b) {
            return 1.0;
        }

        $maior = max(strlen($a), strlen($b));
        if ($maior === 0) {
            return 0.0;
        }

        // levenshtein() do PHP trabalha em bytes e trava acima de 255.
        if ($maior > 255) {
            $a = substr($a, 0, 255);
            $b = substr($b, 0, 255);
            $maior = max(strlen($a), strlen($b));
        }

        $distancia = levenshtein($a, $b);

        return 1.0 - ($distancia / $maior);
    }

    /** Remove artigo inicial: "o senhor dos aneis" -> "senhor dos aneis". */
    public static function semArtigoInicial(string $normalizado): string
    {
        return (string) preg_replace('/^(o|a|os|as|um|uma|the|le|la|el)\s+/u', '', $normalizado);
    }

    public static function truncar(string $texto, int $limite): string
    {
        if (mb_strlen($texto) <= $limite) {
            return $texto;
        }

        return rtrim(mb_substr($texto, 0, $limite - 1)) . '…';
    }
}
