<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Tools;

/**
 * Gotowe scenariusze pracy. Prompty tylko instruują model; nie wykonują
 * odczytu ani zapisu i nie zawierają danych użytkownika.
 */
final class Prompts
{
    public function prepareTradingDay(): array
    {
        return [
            [
                'role' => 'assistant',
                'content' => 'Jesteś asystentem tradingowym AlphaVault. Pracujesz wyłącznie na danych zwróconych przez narzędzia MCP. Nie wymyślaj liczb ani wydarzeń. Brak danych oznacza puste pole.',
            ],
            [
                'role' => 'user',
                'content' => "Przygotuj briefing na dziś.\n\n"
                    . "1. Wywołaj get_day_plan_context (jeden kontekst dnia), get_readiness_context, get_protection_status i get_risk_rules.\n"
                    . "1a. Jeśli plan istnieje, użyj get_day_plan_compliance dla jego daty; jeśli nie, pomiń ocenę zgodności.\n"
                    . "2. Podaj: wynik dnia, gotowość, aktywny plan, najbliższe wydarzenia makro o impact 3 (get_macro_calendar lub get_macro_event_details), limity ryzyka i stan challenge'y (get_prop_challenge_risk).\n"
                    . "3. Wskaż ryzyka: przekroczone limity, brak planu, brak check-inu, wydarzenia w trakcie sesji, aktywne naruszenia z get_risk_violations.\n"
                    . "4. Zakończ trzema konkretnymi zdaniami: co robić, czego nie robić, kiedy przerwać.\n"
                    . "Nie składaj żadnej propozycji zapisu, dopóki użytkownik o to nie poprosi. Plan dnia to wyłącznie create_day_plan_proposal — nigdy nie zapisuj go bez zgody.",
            ],
        ];
    }

    public function reviewTrade(string $tradeId): array
    {
        return [
            [
                'role' => 'assistant',
                'content' => 'Jesteś asystentem tradingowym AlphaVault. Oceniasz proces, nie wynik. Dane pochodzą wyłącznie z MCP. Nie zmieniaj pól finansowych transakcji.',
            ],
            [
                'role' => 'user',
                'content' => "Przeanalizuj transakcję o id " . $tradeId . ".\n\n"
                    . "1. Wywołaj get_trade_journal_context z tradeId=" . $tradeId . ", potem get_trade_review i get_setup_performance dla setupu tej transakcji.\n"
                    . "2. Oceń: zgodność z planem (get_day_plan_compliance dla daty), setup i jego zasady, checklistę, emocję, błędy, R i MFE/MAE.\n"
                    . "3. Wskaż jedną rzecz do powtórzenia i jedną do poprawy.\n"
                    . "4. Jeśli użytkownik poprosi o zapis recenzji, użyj propose_trade_review lub update_trade_proposal (pola dziennika). Nigdy nie proponuj zmiany entry, SL, TP, size, wyniku ani danych giełdowych transakcji importowanej.",
            ],
        ];
    }

    public function weeklyReview(?string $weekStart = null): array
    {
        $range = $weekStart !== null && $weekStart !== ''
            ? 'Tydzień rozpoczynający się ' . $weekStart . '.'
            : 'Ostatnie 7 dni.';

        return [
            [
                'role' => 'assistant',
                'content' => 'Jesteś asystentem tradingowym AlphaVault. Podsumowanie ma być oparte na danych MCP, bez zgadywania i bez tekstów zastępczych.',
            ],
            [
                'role' => 'user',
                'content' => "Przygotuj weekly review. " . $range . "\n\n"
                    . "1. Wywołaj get_stats (7d), list_trades (closed), get_performance_by_setup, get_performance_by_emotion, get_performance_by_error, get_performance_by_grade, get_checkin_trading_correlation i get_trade_patterns.\n"
                    . "2. Podaj: netto, win rate, profit factor, expectancy R, max drawdown, liczbę transakcji.\n"
                    . "3. Wskaż najlepszy i najgorszy setup, zależność wyników od oceny A/B/C i checklisty, emocje i powtarzalne błędy. Wzorce z get_trade_patterns opisuj z ich próbą i pewnością, bez twierdzeń o przyczynowości.\n"
                    . "4. Osobno opisz stan challenge'y: get_prop_challenge_risk (limit dzienny i obsunięcie) oraz get_risk_violations.\n"
                    . "5. Zakończ trzema działaniami na kolejny tydzień. Nie wykonuj zapisów.",
            ],
        ];
    }
}
