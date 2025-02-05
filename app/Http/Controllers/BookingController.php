<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\ProofOfPaymentImage;
use Illuminate\Support\Facades\Storage;
use App\Mail\BookingCancellationMail;
use App\Mail\BookingActivatedMail;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;  
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    public function getBookings(Request $request)
{
    $status = $request->query('status');
    $date = $request->query('date');
    $search = $request->query('search');
    
    // Start query with necessary joins
    $query = Booking::with(['product', 'user', 'orderDetail.proofOfPaymentImage', 'orderDetail.status'])
        ->join('order_details', 'bookings.order_detail_id', '=', 'order_details.id');

    // Filter by status if provided
    if ($status) {
        $query->whereHas('orderDetail.status', function ($query) use ($status) {
            $query->where('id', $status);
        });
    }

    // Filter by date if provided
    if ($date) {
        $query->whereDate('order_details.day', $date);
    }

    // Filter by search query if provided
    if ($search) {
        $query->where(function ($query) use ($search) {
            $query->whereHas('user', function ($query) use ($search) {
                $query->where('username', 'LIKE', '%' . $search . '%');
            })
            ->orWhere('order_details.unique_code', 'LIKE', '%' . $search . '%');
        });
    }

    // Order by status_id
    $query->orderByRaw("FIELD(order_details.status_id, 1, 2, 3)");

    $bookings = $query->select('bookings.*')->get();

    return response()->json($bookings, 200);
}



    public function getBookingById($id)
    {
        $booking = Booking::with(['product', 'user', 'orderDetail.proofOfPaymentImage', 'orderDetail.status', 'orderDetail.paymentMethod'])
                          ->where('id', $id)->first();

        if (!$booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        return response()->json($booking, 200);
    }

    public function getActiveBookings()
    {
        $bookings = Booking::with(['product', 'user', 'orderDetail.proofOfPaymentImage', 'orderDetail.status'])
            ->whereHas('orderDetail', function ($query) {
                $query->whereIn('status_id', [1, 3]) 
                      ->where(function ($query) {
                          $query->where('status_id', 3);
                      });
            })->get();
        
        if ($bookings->isEmpty()) {
            return response()->json(['message' => 'No active bookings found'], 404);
        }
    
        return response()->json($bookings, 200);
    }


    public function getEvaluations()
    {
        $currentDateTime = Carbon::now();

        $bookings = Booking::with(['product', 'user', 'orderDetail.proofOfPaymentImage', 'orderDetail.status'])
                            ->whereHas('orderDetail', function ($query) use ($currentDateTime) {
                                $query->where('status_id', 3) 
                                      ->where('day', '<=', $currentDateTime->toDateString())
                                      ->where('check_out', '<', $currentDateTime->toTimeString());
                            })->get();

        if ($bookings->isEmpty()) {
            return response()->json(['message' => 'No completed bookings found for evaluation'], 200);
        }

        return response()->json($bookings, 200);
    }

    public function getMyOrder()
    {
        $user = Auth::user();

        $bookings = Booking::with(['product', 'product.imageProduct', 'orderDetail.proofOfPaymentImage', 'orderDetail.status'])
            ->where('user_id', $user->id)
            ->get()
            ->sortBy(function($booking) {
                switch ($booking->orderDetail->status->status) {
                    case 'Booking':
                        return 1;
                    case 'Payment':
                        return 2;
                    case 'Active':
                        return 3;
                    case 'Done':
                        return 4;
                    default:
                        return 5;
                }
            })
            ->values()
            ->all();

        if (empty($bookings)) {
            return response()->json(['message' => 'No orders found'], 200);
        }

        return response()->json($bookings, 200);
    }

    public function getBookingCounts()
    {
        $bookingCount = Booking::whereHas('orderDetail', function($query) {
            $query->where('status_id', 1);
        })->count();

        $paymentCount = Booking::whereHas('orderDetail', function($query) {
            $query->where('status_id', 2);
        })->count();

        $activeCount = Booking::whereHas('orderDetail', function($query) {
            $query->where('status_id', 3); 
        })->count();

        return response()->json([
            'booking_count' => $bookingCount,
            'payment_count' => $paymentCount,
            'active_count' => $activeCount,
        ], 200);
    }

    public function createFromUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'order_detail' => 'required|array',
            'order_detail.day' => 'required|date',
            'order_detail.check_in' => 'required|date_format:H:i',
            'order_detail.check_out' => 'required|date_format:H:i',
            'order_detail.payment_method_id' => 'required|exists:payment_methods,id',
            'order_detail.status_id' => 'required|exists:statuses,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $user = Auth::user();
        $product = Product::find($request->product_id);

        $checkIn = Carbon::parse($request->order_detail['check_in']);
        $checkOut = Carbon::parse($request->order_detail['check_out']);
        $duration = $checkIn->diffInHours($checkOut);

        $totalCost = $duration * $product->price;

        $popImage = ProofOfPaymentImage::create(['image_url' => null]);

        $orderDetail = OrderDetail::create([
            'day' => $request->order_detail['day'],
            'check_in' => $request->order_detail['check_in'],
            'check_out' => $request->order_detail['check_out'],
            'unique_code' => Str::uuid(),
            'payment_method_id' => $request->order_detail['payment_method_id'],
            'status_id' => 1, 
            'pop_img_id' => $popImage->id,
            'total_cost' => $totalCost,
        ]);

        $booking = Booking::create([
            'product_id' => $request->product_id,
            'user_id' => $user->id,
            'approval' => false,
            'order_detail_id' => $orderDetail->id,
        ]);

        return response()->json(['message' => 'Booking created successfully', 'booking' => $booking], 201);
    }

    public function getSalesData()
    {
        $salesData = Booking::selectRaw('MONTH(order_details.day) as month, COALESCE(SUM(order_details.total_cost), 0) as total_sales')
            ->join('order_details', 'bookings.order_detail_id', '=', 'order_details.id')
            ->groupByRaw('MONTH(order_details.day)')
            ->get()
            ->keyBy('month')
            ->toArray();
    
        $months = range(1, 12);
        $sales = [];
    
        foreach ($months as $month) {
            $sales[] = [
                'month' => $month,
                'total_sales' => isset($salesData[$month]) ? $salesData[$month]['total_sales'] : 0
            ];
        }
    
        return response()->json($sales, 200);
    }
    
    public function getBookingsThisMonth()
    {
        $currentMonth = Carbon::now()->month;

        // Total Bookings
        $totalBookings = Booking::whereHas('orderDetail', function($query) use ($currentMonth) {
            $query->where('status_id', 1) // Booking
                ->whereMonth('day', $currentMonth);
        })->count();

        // Total Payments
        $totalPayments = Booking::whereHas('orderDetail', function($query) use ($currentMonth) {
            $query->where('status_id', 2) // Payment
                ->whereMonth('day', $currentMonth);
        })->count();

        // Total Active
        $totalActive = Booking::whereHas('orderDetail', function($query) use ($currentMonth) {
            $query->where('status_id', 3) // Active
                ->whereMonth('day', $currentMonth);
        })->count();

        return response()->json([
            'month' => $currentMonth,
            'total_bookings' => $totalBookings,
            'total_payments' => $totalPayments,
            'total_active' => $totalActive,
        ], 200);
    }
    
    public function acceptBooking($id)
    {
        $booking = Booking::with('orderDetail')->find($id);

        if (!$booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        if ($booking->orderDetail->status_id == 2) { 
            return response()->json(['message' => 'Booking already in payment status, cannot be accepted again'], 400);
        }

        $booking->orderDetail->status_id = 2; 
        $booking->approval = true;
        $booking->orderDetail->save();

        Mail::to($booking->user->email)->send(new \App\Mail\BookingAcceptedMail($booking));

        return response()->json(['message' => 'Booking accepted and user notified', 'booking' => $booking], 200);
    }

    public function uploadPaymentProof(Request $request, $id)
    {
        $booking = Booking::with('orderDetail')->find($id);

        if (!$booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'payment_proof' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $imagePath = $request->file('payment_proof')->store('payment_proofs', 'public');

        $popImage = ProofOfPaymentImage::create([
            'image_url' => Storage::url($imagePath),
        ]);

        $booking->orderDetail->pop_img_id = $popImage->id;
        $booking->orderDetail->save();

        $booking->approval = true;
        $booking->save();

        return response()->json(['message' => 'Payment proof uploaded', 'booking' => $booking], 200);
    }

    public function verifyPayment($id)
    {
        $booking = Booking::with('orderDetail.proofOfPaymentImage', 'orderDetail.paymentMethod')->find($id);
    
        if (!$booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }
    
        if ($booking->orderDetail->status_id == 3) {
            return response()->json(['message' => 'Data was Activated'], 400);
        }
    
        if (is_null($booking->orderDetail->proofOfPaymentImage->image_url)) {
            return response()->json(['message' => 'Payment proof not uploaded'], 400);
        }
    
        $details = [
            'username' => $booking->user->username,
            'space_type' => $booking->product->space_type,
            'day' => $booking->orderDetail->day,
            'check_in' => $booking->orderDetail->check_in,
            'check_out' => $booking->orderDetail->check_out,
            'payment_method' => $booking->orderDetail->paymentMethod->name,
        ];
    
        $qrCodeDir = storage_path('app/public/qr_codes');
        if (!file_exists($qrCodeDir)) {
            mkdir($qrCodeDir, 0755, true);
        }
    
        // Generate the QR code
        $qrCode = Builder::create()
            ->writer(new PngWriter())
            ->data(json_encode($details))
            ->build();
    
        $qrCodeFileName = $booking->orderDetail->unique_code . '.png';
        $qrCodePath = 'public/qr_codes/' . $qrCodeFileName;
        
        // Save QR code using the Storage facade
        Storage::put($qrCodePath, $qrCode->getString());
    
        // Check if file exists using Storage facade
        if (!Storage::exists($qrCodePath)) {
            Log::error('QR Code file not found after saving: ' . $qrCodePath);
            return response()->json(['message' => 'QR Code file not found after saving'], 500);
        }
    
        $qrCodeUrl = Storage::url($qrCodePath);
        $booking->qr_code = $qrCodeUrl;
        $booking->orderDetail->status_id = 3;
        $booking->orderDetail->save();
        $booking->save();
    
        $details['qr_code_path'] = $qrCodeUrl;
    
        Mail::to($booking->user->email)->send(new BookingActivatedMail($details, storage_path('app/public/qr_codes/' . $qrCodeFileName)));
    
        return response()->json(['message' => 'Payment verified and booking activated', 'booking' => $booking], 200);
    }    

    public function cancelBooking(Request $request, $id)
    {
        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json(['error' => 'Booking not found'], 404);
        }

        $reason = $request->input('reason');

        try {
            Mail::to($booking->user->email)->send(new BookingCancellationMail($booking, $reason));
            
            $booking->delete();

            return response()->json(['success' => true, 'message' => 'Booking canceled, email sent to user, and booking deleted.']);
        } catch (\Exception $e) {
            Log::error('Error sending cancellation email: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to send email. Booking not deleted.'], 500);
        }
    }
}
