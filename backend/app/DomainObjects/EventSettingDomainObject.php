<?php

namespace HiEvents\DomainObjects;

use HiEvents\DomainObjects\Enums\TicketingMode;

class EventSettingDomainObject extends Generated\EventSettingDomainObjectAbstract
{
    public function isTicketingExternal(): bool
    {
        return $this->getTicketingMode() === TicketingMode::EXTERNAL->name
            && ! empty($this->getExternalTicketUrl());
    }

    /**
     * @todo This should not be here.
     */
    public function getGetEmailFooterHtml(): string
    {
        if ($this->getEmailFooterMessage() === null) {
            return '';
        }

        return <<<HTML
<div style="color: #888; margin-top: 30px; margin-bottom: 30px; font-size: .9em;">
    {$this->getEmailFooterMessage()}
</div>
HTML;
    }
}
