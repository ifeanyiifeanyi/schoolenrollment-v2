<?php

namespace App\Http\Controllers\Admin;

use App\Models\Payment;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\PaymentMethod;
use App\Exports\PaymentsExport;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\File;
use Maatwebsite\Excel\Facades\Excel;

class PaymentMethodController extends Controller
{
    public function index($id = null)
    {
        $paymentMethods = PaymentMethod::latest()->simplePaginate("10");
        $paymentMethod = null;

        if ($id) {
            $paymentMethod = PaymentMethod::findOrFail($id);
            // dd($paymentMethod);
        }

        return view('admin.paymentMethod.index', compact('paymentMethods', 'paymentMethod'));
    }
    protected function storeFile($file, $directory)
    {
        if ($file) {
            $filename = Str::random(20) . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs($directory, $filename);
            return $path;
        }

        return null;
    }
    public function store(Request $request)
    {
        $paymentMethod = new PaymentMethod();
        $request->validate([
            'name' => 'required|string|min:2|unique:payment_methods',
            'description' => 'nullable|string',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,svg|max:10000',
        ]);

        if ($request->hasFile('logo')) {
            $thumb = $request->file('logo');
            $extension = $thumb->getClientOriginalExtension();
            $profilePhoto = time() . "." . $extension;
            $thumb->move('payment/', $profilePhoto);
            $paymentMethod->logo = 'payment/' . $profilePhoto;



            $paymentMethod->name = $request->name;
            $paymentMethod->description = $request->description;
            $paymentMethod->save();
        }



        $notification = [
            'message' => 'Payment Method Created!',
            'alert-type' => 'success'
        ];

        return redirect()->back()->with($notification);
    }

    public function update(Request $request, $id)
    {
        $payment = PaymentMethod::findOrFail($id);
        $validatedData = $request->validate([
            'name' => 'required|string|min:2|unique:payment_methods,name,' . $payment->id,
            'description' => 'nullable|string',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,svg|max:10000',
        ]);

        // Check if a new logo is uploaded
        if ($request->hasFile('logo')) {
            // Delete the old logo if it exists
            if (!empty($payment->logo) && file_exists(public_path($payment->logo))) {
                unlink(public_path($payment->logo));
            }

            // Upload the new logo
            $thumb = $request->file('logo');
            $extension = $thumb->getClientOriginalExtension();
            $logoName = time() . '.' . $extension;
            $thumb->move('payment/', $logoName);
            $validatedData['logo'] = 'payment/' . $logoName;
        } else {
            // Keep the old logo
            $validatedData['logo'] = $payment->logo;
        }

        $payment->update($validatedData);

        $notification = [
            'message' => 'Payment Method Updated!',
            'alert-type' => 'success'
        ];

        return redirect()->route('admin.payment.manage')->with($notification);
    }

    public function destroy($id)
    {
        $payment = PaymentMethod::findOrFail($id);
        if (!empty($payment->logo) && file_exists(public_path($payment->logo))) {
            unlink(public_path($payment->logo));
        }
        $payment->delete();
        $notification = [
            'message' => 'Payment Method Deleted!',
            'alert-type' => 'success'
        ];

        return redirect()->route('admin.payment.manage')->with($notification);
    }



    public function studentApplicationPayment(Request $request)
    {
        $search = $request->input('search');
        $payment_count = Payment::count();
        $payments = Payment::with('user.student', 'application')
            ->whereHas('user', function ($query) use ($search) {
                $query->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('other_names', 'LIKE', "%{$search}%")
                    ->orWhere('transaction_id', 'LIKE', "%{$search}%");
            })
            ->orWhere('transaction_id', 'LIKE', "%{$search}%")
            ->latest()->simplePaginate(300);

        return view('admin.paymentMethod.studentPaymentManager', compact('payments', 'search', 'payment_count'));
    }
    public function exportPayments()
    {
        return Excel::download(new PaymentsExport(), 'payments.xlsx');
    }
}
