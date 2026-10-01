<?php

declare(strict_types=1);

/**
 * Das Formular der Instanz Sony TV ist ohne README verständlich (MCP-Test vom 01.10.2026, Regel 1 in
 * mcp-tauglichkeit.md): Eine KI über den MCP-Server sieht nur das Formular, nicht die Dokumentation.
 * Bis 2.2 build 36 verwies es für den Pre-Shared Key auf „the documentation".
 *
 * Geprüft wird der Inhalt, nicht der Wortlaut: Was der Schlüssel ist, wo er am TV steht, welche Vorgabe gilt,
 * dass „Remote start" fürs Einschalten nötig ist und woran ein falscher Schlüssel zu erkennen ist.
 *
 * Aufruf: php tests/check-form-help.php
 */

require_once __DIR__ . '/harness.php';

$form = json_decode((string)file_get_contents(dirname(__DIR__) . '/Sony TV/form.json'), true, 512, JSON_THROW_ON_ERROR);

/** Alle Elemente in Formularreihenfolge, auch die in Gruppen und Panels. */
function flach(array $elemente): array
{
    $liste = [];
    foreach ($elemente as $e) {
        $liste[] = $e;
        $liste   = array_merge($liste, flach($e['items'] ?? []));
    }
    return $liste;
}

$elemente = flach($form['elements']);
$texte    = implode("\n", array_column(array_filter($elemente, static fn (array $e): bool => $e['type'] === 'Label'), 'caption'));

pruefe(!str_contains(strtolower($texte), 'documentation'), 'kein Verweis auf „the documentation"');

// Was ist der Schlüssel, wo steht er am TV, welche Vorgabe gilt
foreach (['Pre-Shared Key', 'IP control', '0000', 'Remote start'] as $begriff) {
    pruefe(str_contains($texte, $begriff), "Formulartext nennt „$begriff\"");
}

// woran ein falscher Schlüssel zu erkennen ist (Mitschnitt falscher-psk: HTTP 403, „Forbidden")
pruefe(str_contains($texte, '403'), 'Formulartext nennt die Folge eines falschen Schlüssels (Fehler 403)');

// der Hinweis steht unmittelbar vor dem Feld, nicht irgendwo im Formular
$namen = array_map(static fn (array $e): string => $e['name'] ?? $e['type'], $elemente);
$psk   = array_search('PSK', $namen, true);
pruefe($psk !== false && $elemente[$psk - 1]['type'] === 'Label' && str_contains($elemente[$psk - 1]['caption'], 'IP control'), 'der Hinweis zum Schlüssel steht direkt vor dem Feld PSK');

// Host: IP-Adresse, kein Hostname (sonst Status 204)
$host = array_search('Host', $namen, true);
pruefe($host !== false && $elemente[$host - 1]['type'] === 'Label' && str_contains($elemente[$host - 1]['caption'], 'host name'), 'vor dem Feld Host steht, dass ein Hostname nicht geht');

ergebnis();
