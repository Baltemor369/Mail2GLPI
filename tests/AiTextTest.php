<?php

/**
 * Tests unitaires **sans framework** (PHP pur, sans GLPI) des briques IA testables :
 *  - GlpiPlugin\Mail2glpi\AiText::parseUrgency() et ::normalize()
 *  - GlpiPlugin\Mail2glpi\AiClient::extractJson() (méthode privée, via réflexion)
 *
 * Exécution : `php tests/AiTextTest.php` (depuis la racine du dépôt, ou n'importe où).
 * Sortie : liste des cas, puis un récapitulatif. Code de sortie non nul si au moins un échec.
 */

require __DIR__ . '/../plugin/src/AiText.php';
require __DIR__ . '/../plugin/src/AiClient.php';

use GlpiPlugin\Mail2glpi\AiText;
use GlpiPlugin\Mail2glpi\AiClient;

$failures = 0;
$count    = 0;

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function check(string $label, $expected, $actual): void
{
    global $failures, $count;
    $count++;
    $ok = $expected === $actual;
    if (!$ok) {
        $failures++;
    }
    printf(
        "[%s] %s\n",
        $ok ? ' OK ' : 'FAIL',
        $label . ($ok ? '' : ' — attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true))
    );
}

// --- AiText::parseUrgency ---------------------------------------------------
check('urgency int 3', 3, AiText::parseUrgency(3));
check('urgency float 3.0', 3, AiText::parseUrgency(3.0));
check('urgency string "4"', 4, AiText::parseUrgency('4'));
check('urgency string "5.0"', 5, AiText::parseUrgency('5.0'));
check('urgency mot "Faible"', 2, AiText::parseUrgency('Faible'));
check('urgency mot accentué "Très haute"', 5, AiText::parseUrgency('Très haute'));
check('urgency mot "critique"', 5, AiText::parseUrgency('critique'));
check('urgency mot EN "high"', 4, AiText::parseUrgency('high'));
check('urgency hors borne 0 -> null', null, AiText::parseUrgency(0));
check('urgency hors borne 6 -> null', null, AiText::parseUrgency(6));
check('urgency inconnu -> null', null, AiText::parseUrgency('bonjour'));
check('urgency null -> null', null, AiText::parseUrgency(null));
check('urgency float 2.5 -> null', null, AiText::parseUrgency(2.5));

// --- AiText::normalize ------------------------------------------------------
check('normalize accents+casse', 'peripherique', AiText::normalize('Périphérique'));
check('normalize hiérarchie', 'it > support > imprimantes', AiText::normalize('IT > Support > Imprimantes'));
check('normalize trim', 'reseau', AiText::normalize('  Réseau  '));

// --- AiClient::extractJson (privée, via réflexion) --------------------------
$client  = new AiClient([]);
$ref     = new ReflectionMethod(AiClient::class, 'extractJson');
$ref->setAccessible(true);
$extract = static fn(string $s) => $ref->invoke($client, $s);

check('extractJson JSON pur', ['a' => 1], $extract('{"a":1}'));
check('extractJson avec habillage', ['category' => 'X'], $extract('Voici la réponse : {"category":"X"} merci.'));
check('extractJson multi-lignes', ['urgency' => 3], $extract("```json\n{\n  \"urgency\": 3\n}\n```"));
check('extractJson non-JSON -> null', null, $extract('bonjour, aucune donnée ici'));
check('extractJson vide -> null', null, $extract(''));

// --- Récapitulatif ----------------------------------------------------------
printf("\n%d cas, %d échec(s).\n", $count, $failures);
exit($failures === 0 ? 0 : 1);
