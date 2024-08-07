<?php

namespace App\Http\Controllers\Student;

use App\Models\User;
use App\Models\Payment;
use App\Models\Department;
use App\Models\Application;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\PaymentMethod;
use Yabacon\Paystack\Paystack;

use Illuminate\Support\Facades\DB;
use App\Mail\ApplicationStatusMail;

use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;
use Yabacon\Paystack\PaystackClient;
use Illuminate\Support\Facades\Storage;
use KingFlamez\Rave\Facades\Rave as Flutterwave;
use Unicodeveloper\Flutterwave\Facades\Flutterwave as UnicodeveloperFlutterwave;



class ApplicationProcessController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $application = $user->applications->first();
        // dd($application);
        if ($application && is_null($application->payment_id)) {
            // Application form has been filled, but payment is pending
            $notification = [
                'message' => 'Please complete the payment to finalize your application.',
                'alert-type' => 'info'
            ];
            return redirect()->route('payment.view.finalStep', ['userSlug' => $user->nameSlug])->with($notification);
        }
        return view('student.application.index');
    }

    // thi view is for the invoice payment
    public function finalApplicationStep($userSlug)
    {
        $user = User::Where('nameSlug', $userSlug)->first();
        // Check if the user is authenticated
        if (!auth()->check()) {
            return redirect()->route('login')->withErrors('You must be logged in to access this page.');
        }
        // Check if the user was found
        if (!$user) {
            return redirect()->back()->withErrors('User not found.');
        }
        // Check if the authenticated user matches the user found by the slug
        if (auth()->user()->id !== $user->id) {
            return redirect()->back()->withErrors('You are not authorized to access this page.');
        }
        // Find the application associated with the user
        $application = Application::where('user_id', $user->id)->first();
        // Check if the application was found
        if (!$application) {
            return redirect()->back()->withErrors('Application not found for this user.');
        }
        $paymentMethods = PaymentMethod::latest()->get();
        // dd($application->department);


        // the user has already complete application
        if ($user) {
            $applicant = $user->applications->whereNotNull('payment_id')->first();

            $notification = [
                'message' => 'You have already submitted an application!',
                'alert-type' => 'info'
            ];

            if ($applicant) {
                return redirect()->route('student.dashboard')->with($notification);
            }
        }

        return view('student.payment.index', compact('user', 'application', 'paymentMethods'));
    }


    // process flutterWave
    public function processPayment(Request $request)
    {
        // dd($request);
        $request->validate([
            'payment_method_id' => 'required'
        ], [
            'payment_method_id.required' => "Please Select Payment Option to Proceed, Thank you."
        ]);

        $user = auth()->user();
        $application = $user->applications->first();
        // if (!$application || !is_null($application->payment_id)) {
        //     return redirect()->back()->withErrors('Payment has already been made or no application found.');
        // }

        $reference = Flutterwave::generateReference();

        $paymentAmount = $request->amount;
        $paymentMethodId = $request->payment_method_id;

        $paymentMethod = PaymentMethod::find($paymentMethodId);


        if ($paymentMethod->name == "Flutterwave") {
            try {
                $data = [
                    'tx_ref' => $reference,
                    'amount' => $paymentAmount,
                    'currency' => 'NGN',
                    'email' => $user->email,
                    'redirect_url' => route('student.payment.callbackFlutter'),
                    'payment_options' => 'card',
                    'customer' => [
                        'email' => $user->email,
                        "phone_number" => $user->student->phone,
                        "name" => $user->first_name . " " . $user->last_name
                    ],
                    'customizations' => [
                        'title' => 'Application Payment',
                        'description' => 'Payment for application #' . $application->invoice_number,
                    ],
                ];


                $payment = Flutterwave::initializePayment($data);

                if ($payment['status'] !== 'success') {
                    return redirect()->back()->withErrors('An error occurred while processing the payment.');
                }

                // $transactionId = $payment;
                // dd($transactionId);

                return redirect($payment['data']['link']);
            } catch (\Exception $e) {
                dd($e->getMessage());

                return redirect()->back()->withErrors('An error occurred: ' . $e->getMessage());
            }
        } else if ($paymentMethod->name == "Paystack") {
            try {
                $paystack = new \Yabacon\Paystack(config('paystack.secretKey'));

                // Define subaccount details
                $subAccountCode = config('paystack.subAccount');

                $transaction = $paystack->transaction->initialize([
                    'email' => $user->email,
                    'amount' => $paymentAmount * 100, // Convert amount to kobo
                    'reference' => $this->generateUniqueReference(),
                    'callback_url' => route('student.payment.callbackPaystack'),
                    'subaccount' => $subAccountCode,
                    'bearer' => 'subaccount' // Ensures the fee is borne by the sub-account
                ]);

                return redirect($transaction->data->authorization_url);
            } catch (\Yabacon\Paystack\Exception\ApiException $e) {
                Log::error('Paystack API Error: ' . $e->getMessage(), [
                    'exception' => $e
                ]);
                return redirect()->back()->withErrors('An error occurred while initializing the payment. Please try again later.');
            } catch (\Exception $e) {
                Log::error('Error in making payment: ' . $e->getMessage(), [
                    'exception' => $e
                ]);
                return redirect()->back()->withErrors('An unexpected error occurred. Please try again later.');
            }
        } else {
            return redirect()->back()->withErrors('Payment Method not found.');
        }
    }


    // // PAY-STACK CALLBACK
    // public function handlePaymentCallBackPayStack(Request $request)
    // {
    //     $paystack = new \Yabacon\Paystack(config('paystack.secretKey'));

    //     try {
    //         DB::beginTransaction();

    //         $transaction = $paystack->transaction->verify([
    //             'reference' => $request->reference,
    //         ]);

    //         if ($transaction->data->status === 'success') {
    //             $user = User::where('email', $transaction->data->customer->email)->first();

    //             if ($user) {
    //                 $application = $user->applications()->first();
    //                 $paymentMethodId = PaymentMethod::where('name', 'Paystack')->first()->id;

    //                 $paymentData = [
    //                     'user_id' => $user->id,
    //                     'amount' => $transaction->data->amount / 100, // Convert kobo to Naira
    //                     'payment_method' => 'Paystack',
    //                     'payment_status' => 'Successful',
    //                     'transaction_id' => $transaction->data->reference,
    //                     'payment_method_id' => $paymentMethodId,
    //                 ];

    //                 $payment = Payment::create($paymentData);

    //                 if ($application) {
    //                     $application->update(['payment_id' => $payment->id]);
    //                 }
    //                 Mail::to($user->email)->send(new ApplicationStatusMail($user, $application, $payment));
    //                 $barcodeUrl = route('student.details.show', ['nameSlug' => $user->nameSlug]);

    //                 return view('student.payment.success', [
    //                     'user' => $user,
    //                     'application' => $application,
    //                     'payment' => $payment,
    //                     'barcodeUrl' => $barcodeUrl
    //                 ]);

    //                 DB::commit();
    //             } else {
    //                 $payment = Payment::where('transaction_id', $$transaction->data->reference)->first();
    //                 if ($payment) {
    //                     $payment->update(['payment_status' => 'failed']);
    //                 }
    //                 $userSlug = optional(auth()->user())->nameSlug;
    //                 return redirect()->route('payment.view.finalStep', ['userSlug' => $userSlug])
    //                     ->withErrors('Payment was not successful. Please try again.');
    //             }
    //         } else {
    //             // Handle declined transaction
    //             $payment = Payment::where('transaction_id', $$transaction->data->reference)->first();
    //             if ($payment) {
    //                 $payment->update(['payment_status' => 'failed']);
    //             }

    //             $userSlug = optional(auth()->user())->nameSlug;

    //             return redirect()->route('payment.view.finalStep', ['userSlug' => $userSlug])
    //                 ->withErrors('Payment was declined: ' . $transaction->data->gateway_response);
    //         }
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         $userSlug = optional(auth()->user())->nameSlug;
    //         Log::error('Error processing payment', ['message' => $e->getMessage()]);
    //         return redirect()->route('payment.view.finalStep', ['userSlug' => $userSlug])
    //             ->withErrors('An error occurred while processing the payment. Please try again.');
    //     }
    // }

    // PAY-STACK CALLBACK
    public function handlePaymentCallBackPayStack(Request $request)
    {
        $paystack = new \Yabacon\Paystack(config('paystack.secretKey'));

        try {
            DB::beginTransaction();

            $transaction = $paystack->transaction->verify([
                'reference' => $request->reference,
            ]);

            if ($transaction->data->status === 'success') {
                $user = User::where('email', $transaction->data->customer->email)->firstOrFail();

                $application = $user->applications()->firstOrFail();
                $paymentMethod = PaymentMethod::where('name', 'Paystack')->firstOrFail();

                $paymentData = [
                    'user_id' => $user->id,
                    'amount' => $transaction->data->amount / 100, // Convert kobo to Naira
                    'payment_method' => 'Paystack',
                    'payment_status' => 'Successful',
                    'transaction_id' => $transaction->data->reference,
                    'payment_method_id' => $paymentMethod->id,
                ];

                $payment = Payment::create($paymentData);
                $application->update(['payment_id' => $payment->id]);

                DB::commit();

                // Send email after transaction is committed
                Mail::to($user->email)->send(new ApplicationStatusMail($user, $application, $payment));

                $barcodeUrl = route('student.details.show', ['nameSlug' => $user->nameSlug]);

                return view('student.payment.success', [
                    'user' => $user,
                    'application' => $application,
                    'payment' => $payment,
                    'barcodeUrl' => $barcodeUrl
                ]);
            } else {
                // Handle declined transaction
                DB::rollBack();
                $userSlug = auth()->user()->nameSlug ?? null;
                return redirect()->route('payment.view.finalStep', ['userSlug' => $userSlug])
                    ->withErrors('Payment was declined: ' . $transaction->data->gateway_response);
            }
        } catch (\Yabacon\Paystack\Exception\ApiException $e) {
            DB::rollBack();
            Log::error('Paystack API Error', ['message' => $e->getMessage()]);
            return $this->handlePaymentError('An error occurred while verifying the payment.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            Log::error('Model Not Found', ['message' => $e->getMessage()]);
            return $this->handlePaymentError('User or application not found.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('General Error', ['message' => $e->getMessage()]);
            return $this->handlePaymentError('An unexpected error occurred. Please try again.');
        }
    }

    private function handlePaymentError($message)
    {
        $userSlug = auth()->user()->nameSlug ?? null;
        return redirect()->route('payment.view.finalStep', ['userSlug' => $userSlug])
            ->withErrors($message);
    }






    // handle FLUTTER-WAVE payment callback
    public function handlePaymentCallBack(Request $request)
    {
        try {
            $status = $request->input('status');
            if ($status === 'successful') {

                $transactionId = Flutterwave::getTransactionIDFromCallback();
                $data = Flutterwave::verifyTransaction($transactionId);
                // dd($data);

                $user = User::where('email', $data['data']['customer']['email'])->first();

                if ($user) {
                    $application = $user->applications()->first();
                    $paymentMethodId = PaymentMethod::where('name', 'Flutterwave')->first()->id;
                    // dd($paymentMethodId);


                    $paymentData = [
                        'user_id' => $user->id,
                        'amount' => $data['data']['amount'],
                        'payment_method' => 'Flutterwave',
                        'payment_status' => 'Successful',
                        'transaction_id' => $transactionId,
                        'payment_method_id' => $paymentMethodId,
                    ];

                    $payment = Payment::create($paymentData);

                    if ($application) {
                        $application->update(['payment_id' => $payment->id]);
                    }
                    Mail::to($user->email)->send(new ApplicationStatusMail($user, $application, $payment));

                    $barcodeUrl = route('student.details.show', ['nameSlug' => $user->nameSlug]);


                    return view('student.payment.success', [
                        'user' => $user,
                        'application' => $application,
                        'payment' => $payment,
                        'barcodeUrl' => $barcodeUrl
                    ]);
                } else {
                    $userSlug = optional(auth()->user())->nameSlug;
                    return redirect()->route('payment.view.finalStep', ['userSlug' => $userSlug])
                        ->withErrors('Payment was not successful. Please try again.');
                }
            } elseif ($status == 'cancelled') {

                $userSlug = optional(auth()->user())->nameSlug;
                return redirect()->route('payment.view.finalStep', ['userSlug' => $userSlug])
                    ->withErrors('Payment was cancelled.');
            } else {

                $userSlug = optional(auth()->user())->nameSlug;
                return redirect()->route('payment.view.finalStep', ['userSlug' => $userSlug])
                    ->withErrors('Payment was not successful. Please try again.');
            }
        } catch (\Exception $e) {
            // dd($e->getMessage());

            // Redirect with an error message
            $userSlug = optional(auth()->user())->nameSlug;
            return redirect()->route('payment.view.finalStep', ['userSlug' => $userSlug])
                ->withErrors('An error occurred while processing the payment. Please try again.');
        }
    }

    public function showSuccess()
    {
        $user = auth()->user();
        $application = $user->applications->first();
        $payment = $application ? $application->payment : null;

        // Generate URL to the student details page
        $barcodeUrl = route('student.details.show', ['nameSlug' => $user->nameSlug]);


        if (!$payment || !$user || $application) {
            return redirect()->route('payment.view.finalStep', ['userSlug' => $user->nameSlug])
                ->withErrors('An error occurred while processing the payment. Please try again.');
        }

        return view('student.payment.success', compact('user', 'application', 'payment', 'barcodeUrl'));
    }


    private function generateUniqueReference()
    {
        // Generate a unique reference for the transaction
        // combination of user ID, timestamp, and a random string

        $userId = auth()->user()->id;
        $timestamp = time();
        // $randomString = Str::random(10);
        return $userId . '' . $timestamp;
    }
}
