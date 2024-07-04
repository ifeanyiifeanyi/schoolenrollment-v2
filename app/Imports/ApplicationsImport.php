<?php

namespace App\Imports;

use App\Models\Student;
use App\Models\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Mail\AdmissionStatusUpdated;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ApplicationsImport implements ToModel, WithHeadingRow
{
    private $errors = [];

    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */

    public function model(array $row)
    {
        // Begin transaction to ensure data integrity
        DB::beginTransaction();

        try {
            // Find the Student by the application_unique_number
            $student = Student::where('application_unique_number', $row['application_no'])->first();

            if (!$student) {
                $this->errors[] = "Student with application number " . $row['application_no'] . " not found.";
                DB::rollBack();
                return null;
            }

            // Find the corresponding Application record through the User model
            $application = Application::where('user_id', $student->user_id)
                ->whereNotNull('payment_id')
                ->first();

            if (!$application) {
                $this->errors[] = "Application for student " . $student->full_name . " with valid payment_id not found.";
                DB::rollBack();
                return null;
            }

            // Update the student's exam score and application's admission status
            $student->exam_score = $row['exam_score'];
            $student->admission_status = $row['admission_status'];
            $student->save();

            $application->admission_status = $row['admission_status'];
            $application->save();

            // Send email notification regardless of admission status change
            Mail::to($student->user->email)->send(new AdmissionStatusUpdated($student, $application));

            // Commit the transaction
            DB::commit();
        } catch (\Exception $e) {
            // Rollback the transaction in case of error
            DB::rollBack();
            // Optionally, log the error or handle it as needed
            Log::error('Error during import: ' . $e->getMessage());
            $this->errors[] = "Error during import: " . $e->getMessage();
            return null;
        }

        // Return the application if needed or null
        return isset($application) ? $application : null;
    }

    public function getErrors()
    {
        return $this->errors;
    }
}
