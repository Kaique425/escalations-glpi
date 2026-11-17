<?php

namespace GlpiPlugin\Escalations;

/** Peças de tela compartilhadas pelas páginas do plugin (mesmo padrão visual do Customer 360 / Marketing). */
class Ui
{
    public static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }

    public static function badge(string $label, string $color, string $icon = ''): string
    {
        return "<span class='badge esc-badge' style='--c:$color'>" . ($icon ? "<i class='ti $icon me-1'></i>" : '') . self::e($label) . '</span>';
    }

    public static function button(string $label, string $url, string $icon = 'ti-plus', string $cls = 'btn-primary'): string
    {
        return "<a class='btn $cls' href='" . self::e($url) . "'><i class='ti $icon me-1'></i>" . self::e($label) . '</a>';
    }

    /** Botão de formulário (submit) com name/value. */
    public static function submit(string $label, string $name, string $value, string $icon = 'ti-check', string $cls = 'btn-primary', string $attrs = ''): string
    {
        return "<button class='btn $cls' name='" . self::e($name) . "' value='" . self::e($value) . "' $attrs><i class='ti $icon me-1'></i>" . self::e($label) . '</button>';
    }

    public static function title(string $title, string $subtitle = '', string $actions = '', string $crumb = 'Escalations'): string
    {
        return "<div class='esc-title'><div><div class='esc-crumb'>" . self::e($crumb) . '</div><h2>' . self::e($title) . '</h2>'
            . ($subtitle !== '' ? "<div class='text-secondary small esc-subtitle'>" . self::e($subtitle) . '</div>' : '')
            . '</div>' . ($actions ? "<div class='d-flex flex-wrap gap-2 align-items-center'>$actions</div>" : '') . '</div>';
    }

    /** Rótulo do nível: "N2 · Especialistas". Se o nome já começa com "N2" ou "Nível 2", usa só o nome (evita "N2 N2 · ..."). */
    public static function levelLabel(int $pos, string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'N' . $pos;
        }
        return preg_match('/^(N\s?\d+|Nível\s*\d+)/iu', $name) ? $name : 'N' . $pos . ' · ' . $name;
    }

    // ------------------------------------------------------------------ datas e tempos

    public static function date(?string $d): string
    {
        return $d && $d !== '0000-00-00' && $d !== '0000-00-00 00:00:00' ? date('d/m/Y', strtotime($d)) : '—';
    }

    public static function dt(?string $d): string
    {
        return $d && $d !== '0000-00-00 00:00:00' ? date('d/m/Y H:i', strtotime($d)) : '—';
    }

    public static function dtSec(?string $d): string
    {
        return $d && $d !== '0000-00-00 00:00:00' ? date('d/m/Y H:i:s', strtotime(substr($d, 0, 19))) : '—';
    }

    /** Duração legível: "45 s", "12 min", "3 h 05", "2 d 4 h". */
    public static function dur(?int $sec): string
    {
        if ($sec === null) {
            return '—';
        }
        $neg = $sec < 0;
        $sec = abs($sec);
        if ($sec < 60) {
            $t = $sec . ' s';
        } elseif ($sec < 3600) {
            $t = (int) floor($sec / 60) . ' min';
        } elseif ($sec < 86400) {
            $t = (int) floor($sec / 3600) . ' h ' . str_pad((string) ((int) floor($sec % 3600 / 60)), 2, '0', STR_PAD_LEFT);
        } else {
            $t = (int) floor($sec / 86400) . ' d ' . (int) floor($sec % 86400 / 3600) . ' h';
        }
        return ($neg ? '-' : '') . $t;
    }

    /** URL absoluta (e-mail, Teams, WhatsApp precisam de endereço completo). */
    public static function absUrl(string $path): string
    {
        global $CFG_GLPI;
        $base = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
        return ($base !== '' ? $base : '') . $CFG_GLPI['root_doc'] . $path;
    }

    // ------------------------------------------------------------------ campos

    public static function filter(string $label, string $control, string $style = ''): string
    {
        return '<div' . ($style !== '' ? " style='$style'" : '') . "><label class='lbl'>" . self::e($label) . "</label>$control</div>";
    }

    public static function select(string $name, array $options, $value = '', string $attrs = '', string $blank = ''): string
    {
        $html = "<select class='form-select' name='" . self::e($name) . "' $attrs>";
        if ($blank !== '') {
            $html .= "<option value=''>" . self::e($blank) . '</option>';
        }
        foreach ($options as $k => $label) {
            $html .= "<option value='" . self::e($k) . "'" . ((string) $k === (string) $value ? ' selected' : '') . '>' . self::e($label) . '</option>';
        }
        return $html . '</select>';
    }

    /** Seleção múltipla (valores = lista de ids marcados). */
    public static function multi(string $name, array $options, array $values, int $size = 6): string
    {
        $html = "<select class='form-select' multiple size='$size' name='" . self::e($name) . "[]'>";
        foreach ($options as $k => $label) {
            $html .= "<option value='" . self::e($k) . "'" . (in_array((int) $k, array_map('intval', $values), true) ? ' selected' : '') . '>' . self::e($label) . '</option>';
        }
        return $html . '</select>';
    }

    public static function input(string $name, $value = '', string $type = 'text', string $attrs = ''): string
    {
        return "<input class='form-control' type='$type' name='" . self::e($name) . "' value='" . self::e($value) . "' $attrs>";
    }

    public static function check(string $name, bool $checked, string $label, string $value = '1'): string
    {
        return "<label class='form-check'><input class='form-check-input' type='checkbox' name='" . self::e($name) . "' value='" . self::e($value) . "'" . ($checked ? ' checked' : '')
            . "><span class='form-check-label'>" . self::e($label) . '</span></label>';
    }

    public static function field(string $label, string $control, string $cols = 'col-md-4', string $hint = ''): string
    {
        return "<div class='$cols'><label class='form-label'>" . self::e($label) . "</label>$control"
            . ($hint !== '' ? "<div class='form-hint small text-secondary'>" . self::e($hint) . '</div>' : '') . '</div>';
    }

    public static function formOpen(string $action, string $cls = ''): string
    {
        return "<form method='post' action='" . self::e($action) . "' class='$cls'>";
    }

    /** Fecha o formulário com o token CSRF do GLPI. */
    public static function closeForm(): void
    {
        \Html::closeForm();
    }

    // ------------------------------------------------------------------ usuários, grupos

    /** Usuários ativos (id => nome). */
    public static function users(): array
    {
        static $cache = null;
        if ($cache === null) {
            global $DB;
            $cache = [];
            foreach ($DB->request(['SELECT' => ['id', 'name', 'realname', 'firstname'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0], 'ORDER' => ['realname', 'firstname', 'name'], 'LIMIT' => 2000]) as $u) {
                $full = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? ''));
                $cache[(int) $u['id']] = $full !== '' ? $full : $u['name'];
            }
        }
        return $cache;
    }

    public static function userName(int $id): string
    {
        return $id ? (self::users()[$id] ?? ('#' . $id)) : '—';
    }

    /** Grupos do GLPI (id => nome completo). */
    public static function groups(): array
    {
        static $cache = null;
        if ($cache === null) {
            global $DB;
            $cache = [];
            foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_groups', 'ORDER' => 'completename', 'LIMIT' => 2000]) as $g) {
                $cache[(int) $g['id']] = $g['completename'];
            }
        }
        return $cache;
    }

    /** Entidades do GLPI (id => nome completo). */
    public static function entities(): array
    {
        static $cache = null;
        if ($cache === null) {
            global $DB;
            $cache = [];
            foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_entities', 'ORDER' => 'completename']) as $e) {
                $cache[(int) $e['id']] = $e['completename'];
            }
        }
        return $cache;
    }

    // ------------------------------------------------------------------ peças visuais

    public static function avatar(string $name, string $title = ''): string
    {
        $name = trim($name);
        if ($name === '' || $name === '—') {
            return "<span class='esc-av none' title='Sem responsável'>?</span>";
        }
        $parts = preg_split('/\s+/', $name);
        $ini = mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : mb_substr($parts[0], 1, 1)));
        return "<span class='esc-av' style='--h:" . (crc32($name) % 360) . "' title='" . self::e($title !== '' ? $title : $name) . "'>" . self::e($ini) . '</span>';
    }

    public static function stCell(string $label, string $color): string
    {
        return "<div class='esc-st' style='--c:$color'>" . self::e($label) . '</div>';
    }

    public static function timeline(float $pct): string
    {
        return "<div class='esc-tl'><span style='width:" . round(max(0, min(100, $pct)), 1) . "%'></span></div>";
    }

    public static function bar(float $pct, string $color = '#3b82f6'): string
    {
        $pct = max(0, min(100, $pct));
        return "<div class='esc-bar'><div style='width:" . round($pct, 1) . "%;background:$color'></div></div>";
    }

    public static function kpi(string $label, string $value, string $hint = '', string $color = '', string $drawer = ''): string
    {
        $tag = $drawer !== '' ? 'button' : 'div';
        return "<$tag" . ($drawer !== '' ? " type='button' data-drawer='" . self::e($drawer) . "'" : '') . " class='esc-kpi" . ($drawer !== '' ? ' esc-click' : '') . "'" . ($color ? " style='--c:$color'" : '')
            . "><div class='esc-kpi-l'>" . self::e($label) . "</div><div class='esc-kpi-v'>$value</div>"
            . ($hint !== '' ? "<div class='esc-kpi-h'>" . self::e($hint) . '</div>' : '') . "</$tag>";
    }

    public static function flash(string $msg, string $type = 'success'): string
    {
        return "<div class='alert alert-$type'><div>" . self::e($msg) . '</div></div>';
    }

    public static function pager(int $total, int $page, int $perPage, array $params): string
    {
        $pages = max(1, (int) ceil($total / $perPage));
        if ($pages <= 1) {
            return '';
        }
        $qs = http_build_query(array_filter($params, fn ($v) => $v !== '' && $v !== null && $v !== 0));
        $html = "<div class='card-footer d-flex gap-2 flex-wrap'>";
        for ($i = 1; $i <= $pages; $i++) {
            $html .= "<a class='btn btn-sm " . ($i === $page ? 'btn-primary' : 'btn-outline-secondary') . "' href='?page=$i" . ($qs ? '&' . self::e($qs) : '') . "'>$i</a>";
        }
        return $html . '</div>';
    }

    /**
     * Tabela principal (estilo monday): grupos recolhíveis, linha com borda colorida, célula de status colorida.
     *
     * @param array $cols   colunas depois do nome: [[rótulo, largura css], ...]
     * @param array $groups [['title' =>, 'color' =>, 'rows' => [['name' =>, 'sub' =>, 'url' =>, 'cells' => [[html, classe], ...], 'late' => bool, 'drawer' => chave]]]]
     */
    public static function mainTable(array $cols, array $groups, string $empty = 'Nada a mostrar.'): string
    {
        $tpl = 'minmax(230px, 1fr) ' . implode(' ', array_column($cols, 1));
        $html = "<div class='esc-table-main' style='--esc-cols:$tpl'>";
        if (!$groups) {
            return $html . "<div class='card card-body text-center text-secondary'>" . self::e($empty) . '</div></div>';
        }
        foreach ($groups as $g) {
            $html .= "<section class='esc-grp' style='--g:" . self::e($g['color']) . "'><div class='esc-grp-head'><button type='button' class='esc-grp-title' data-toggle aria-expanded='true'><i class='ti ti-chevron-down'></i><span>"
                . self::e($g['title']) . '</span><em>' . count($g['rows']) . "</em></button><div class='esc-col-names'>";
            foreach ($cols as [$label]) {
                $html .= '<span>' . self::e($label) . '</span>';
            }
            $html .= "</div></div><div class='esc-grp-body'>";
            foreach ($g['rows'] as $r) {
                $html .= "<div class='esc-row" . (!empty($r['late']) ? ' late' : '') . "'" . (!empty($r['drawer']) ? " tabindex='0' role='button' data-drawer='" . self::e($r['drawer']) . "'" : '') . '>'
                    . "<div class='esc-cell-name'><a href='" . self::e($r['url']) . "'>" . self::e($r['name']) . '</a>' . (!empty($r['sub']) ? '<small>' . self::e($r['sub']) . '</small>' : '') . '</div>';
                foreach ($r['cells'] as [$cell, $cls]) {
                    $html .= "<div class='$cls'>$cell</div>";
                }
                $html .= '</div>';
            }
            $html .= '</div></section>';
        }
        return $html . '</div>';
    }
}
