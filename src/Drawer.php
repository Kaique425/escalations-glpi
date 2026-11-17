<?php

namespace GlpiPlugin\Escalations;

/**
 * Gaveta lateral (a mesma dos plugins Marketing, CRM, PMO...). Cada tela registra o conteúdo com
 * Drawer::add() e, no fim, Menu::footer() imprime os <template id="dr-chave">; um elemento com
 * data-drawer="chave" abre a gaveta (drawer.js).
 */
class Drawer
{
    private static array $templates = [];

    public static function add(string $key, string $title, string $icon, string $color, string $html): void
    {
        self::$templates[$key] = [$title, $icon, $color, $html];
    }

    public static function has(string $key): bool
    {
        return isset(self::$templates[$key]);
    }

    public static function flush(): string
    {
        $out = '';
        foreach (self::$templates as $key => [$title, $icon, $color, $html]) {
            $out .= "<template id='dr-" . Ui::e($key) . "' data-title='" . Ui::e($title) . "' data-icon='" . Ui::e($icon) . "' data-color='" . Ui::e($color) . "'><div>$html</div></template>";
        }
        self::$templates = [];
        return $out;
    }

    /** Texto de apoio no topo da gaveta. */
    public static function lead(string $text): string
    {
        return "<div class='esc-dr-lead'>" . Ui::e($text) . '</div>';
    }

    public static function section(string $text): string
    {
        return "<div class='esc-dr-sec'>" . Ui::e($text) . '</div>';
    }

    /** Um item: título, linha de apoio, valor/selo à direita (HTML já seguro) e, se houver, o link. */
    public static function item(string $title, string $meta = '', string $right = '', string $url = '', string $color = '#3b82f6'): string
    {
        $tag = $url !== '' ? 'a' : 'div';
        return "<$tag class='esc-dr-item' style='--oc:$color'" . ($url !== '' ? " href='" . Ui::e($url) . "'" : '') . "><div class='d-flex justify-content-between gap-2 align-items-center'><div><div class='t'>"
            . Ui::e($title) . '</div>' . ($meta !== '' ? "<div class='m'>" . Ui::e($meta) . '</div>' : '') . '</div>' . ($right !== '' ? "<div class='text-end'>$right</div>" : '') . "</div></$tag>";
    }

    /**
     * Lista de itens [título, meta, direita, url, cor] com texto de apoio e total opcional.
     * Sem itens, mostra uma mensagem.
     */
    public static function list(array $items, string $lead = '', string $totalLabel = '', string $totalValue = '', string $empty = 'Nada a mostrar aqui.'): string
    {
        $html = $lead !== '' ? self::lead($lead) : '';
        foreach ($items as $i) {
            $html .= self::item($i[0], $i[1] ?? '', $i[2] ?? '', $i[3] ?? '', $i[4] ?? '#3b82f6');
        }
        if (!$items) {
            $html .= "<div class='text-secondary'>" . Ui::e($empty) . '</div>';
        }
        if ($totalLabel !== '') {
            $html .= "<div class='esc-dr-total'><span>" . Ui::e($totalLabel) . "</span><span>$totalValue</span></div>";
        }
        return $html;
    }

    /** Botões no pé da gaveta: [[rótulo, url, ícone, classe], ...]. */
    public static function actions(array $buttons): string
    {
        $html = "<div class='esc-dr-actions'>";
        foreach ($buttons as [$label, $url, $icon, $cls]) {
            $html .= Ui::button($label, $url, $icon, $cls);
        }
        return $html . '</div>';
    }
}
