<?php

namespace App\Infrastructure\Notifications;

use Illuminate\Mail\Mailable;

class QualificationEmail extends Mailable
{
    public function __construct(public array $details) {}

    public function build(): self
    {
        $lines = [];
        foreach ($this->details as $label => $value) {
            $lines[] = e($label).': '.e((string) $value);
        }

        return $this->subject('KioTrad qualified: '.$this->details['Team'].' · '.$this->details['Scheduled WAT'])
            ->html('<h2>Qualified opportunity</h2><p>'.implode('<br>', $lines).'</p>');
    }
}
