<?php

namespace GlpiPlugin\Escalations;

use CommonGLPI;
use Html;
use Profile;
use Session;

/** Aba "Escalations" em Administração → Perfis: direitos do plugin por perfil. */
class ProfileTab extends CommonGLPI
{
    public static function getRights($interface = 'central')
    {
        return [
            READ => __('Read'),
            UPDATE => __('Update'),
            CREATE => __('Create'),
            PURGE => __('Delete permanently'),
            Escalation::ACK => 'Reconhecer (ACK)',
            Escalation::RESOLVE => 'Resolver',
            Escalation::SILENCE => 'Silenciar e reenviar avisos',
            Escalation::CANCEL => 'Cancelar escalonamento',
            Escalation::LOGS => 'Ver logs rastreáveis e mensagens enviadas',
            Escalation::SLO => 'Ver SLO, SLA e análise de gaps',
            Escalation::MANAGE => 'Administrar hierarquias, canais, permissões e configuração',
            Escalation::ALL => 'Ver todos os escalonamentos (sem isso, só os que acionaram o usuário)',
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry('Escalations', 0, null, 'ti ti-urgent');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Profile) {
            return false;
        }
        $canedit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);
        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . htmlspecialchars(Profile::getFormURL(), ENT_QUOTES) . "'>";
        }
        $item->displayRightsChoiceMatrix(
            [['itemtype' => self::class, 'label' => 'Escalations', 'field' => Escalation::$rightname]],
            ['canedit' => $canedit, 'default_class' => 'tab_bg_2', 'title' => 'Escalations']
        );
        echo "<div class='alert alert-info mt-2'><div><i class='ti ti-info-circle me-1'></i>Estes mesmos direitos também podem ser editados <b>dentro do plugin</b>, em <b>Escalations → Permissões</b>, com modelos de perfil, escopo de hierarquias, \"somente notificado\" e exceções por usuário.</div></div>"
            . "<div class='small text-secondary mt-2'><b>Ler</b>: vê o painel e os escalonamentos em que foi acionado. <b>Reconhecer, Resolver, Silenciar, Cancelar</b>: ações sobre os escalonamentos que pode ver "
            . '(quem foi acionado como <i>escalado</i> pode reconhecer, resolver e silenciar o seu mesmo sem esses direitos). <b>Atualizar</b>: vale como todas as ações juntas. '
            . '<b>Logs</b> e <b>SLO/gaps</b>: telas de auditoria e de indicadores. <b>Criar</b>: abre escalonamento manual. <b>Administrar</b>: hierarquias, regras, canais, webhook e <b>Permissões</b>. '
            . 'Perfil que só deve ser <b>notificado</b>: deixe só Ler (ou nada) e marque "somente notificado" em Escalations → Permissões; as exceções por usuário e o escopo de hierarquias também ficam lá.</div>';
        if ($canedit) {
            echo "<div class='center mt-2'><input type='hidden' name='id' value='" . (int) $item->getID() . "'>";
            echo "<button type='submit' name='update' value='1' class='btn btn-primary'>" . _x('button', 'Save') . '</button></div>';
            Html::closeForm();
        }
        echo '</div>';
        return true;
    }
}
