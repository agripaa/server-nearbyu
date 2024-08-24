<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class BookingCancellationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $booking;
    public $reason;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($booking, $reason)
    {
        $this->booking = $booking;
        $this->reason = $reason;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->view('emails.booking_cancellation')
                    ->subject('Booking Cancellation')
                    ->with([
                        'booking' => $this->booking,
                        'reason' => $this->reason,
                    ]);
    }
}
