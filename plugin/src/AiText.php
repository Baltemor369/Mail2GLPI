<?php

namespace GlpiPlugin\Mail2glpi;

/**
 * Helpers texte **purs** (sans dépendance GLPI) pour l'enrichissement IA : normalisation de
 * chaînes (comparaison tolérante des catégories) et interprétation de l'urgence renvoyée par le
 * modèle. Isolés ici pour être testables unitairement (cf. tests/AiTextTest.php).
 */
final class AiText
{
    /**
     * Normalise une chaîne pour une comparaison tolérante : minuscules + suppression des accents
     * (décomposition Unicode puis retrait des marques diacritiques). Sans l'extension intl, on se
     * limite à la mise en minuscules (dégradation gracieuse).
     */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        if (class_exists('Normalizer')) {
            $decomposed = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $s = (string) preg_replace('/\p{Mn}/u', '', $decomposed);
            }
        }
        return $s;
    }

    /**
     * Convertit l'urgence renvoyée par le modèle en niveau GLPI 1-5. Accepte un entier, un flottant
     * à décimale nulle (« 3.0 »), une chaîne numérique, ou un mot fréquent FR/EN (« Faible »,
     * « Haute », « critique »…). Renvoie null si rien d'exploitable.
     *
     * @param mixed $raw
     */
    public static function parseUrgency($raw): ?int
    {
        // "urgency": 3 -> int ; 3.0 -> float entier ; "3"/"3.0" -> string numérique (certains
        // modèles, hors response_format, émettent des décimaux à zéro). On refuse un flottant
        // fractionnaire (2.5) : ambigu, on préfère ne rien poser.
        if (is_int($raw)
            || (is_float($raw) && floor($raw) === $raw)
            || (is_string($raw) && preg_match('/^\d+(?:\.0+)?$/', trim((string) $raw)) === 1)
        ) {
            $n = (int) $raw;
            return ($n >= 1 && $n <= 5) ? $n : null;
        }
        if (!is_string($raw)) {
            return null;
        }
        $map = [
            'tres basse' => 1, 'tres faible' => 1, 'very low' => 1,
            'basse' => 2, 'faible' => 2, 'low' => 2,
            'moyenne' => 3, 'normale' => 3, 'medium' => 3, 'normal' => 3,
            'haute' => 4, 'elevee' => 4, 'high' => 4, 'importante' => 4,
            'tres haute' => 5, 'tres elevee' => 5, 'critique' => 5, 'urgente' => 5,
            'urgent' => 5, 'very high' => 5, 'critical' => 5,
        ];
        return $map[self::normalize($raw)] ?? null;
    }
}
