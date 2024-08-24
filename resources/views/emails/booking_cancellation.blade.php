<!DOCTYPE html>
<html>
<head>
    <title>Booking Cancellation</title>
</head>
<body>
    <h1>Booking Cancellation</h1>
    <p>Dear {{ $booking->user->username }},</p>
    <p>Your booking with code {{ $booking->code }} has been canceled. Here is the reason provided:</p>
    <p><strong>{{ $reason }}</strong></p>
    <p>Thank you for using our service.</p>
</body>
</html>
