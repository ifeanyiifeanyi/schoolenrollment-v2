<?php

namespace App\Http\Controllers\Admin;

use App\Models\Scholarship;
use Illuminate\Http\Request;
use App\Models\ScholarQuestion;
use App\Http\Controllers\Controller;
use Illuminate\Validation\ValidationException;

class ScholarshipQuestionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        
        $scholarships = Scholarship::all();


        return view('admin.scholarshipQuestions.index', compact('scholarships'));
    }



    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'scholarship_id' => 'required|exists:scholarships,id',
                'questions' => 'required|array',
                'questions.*.question_text' => 'required|string',
                'questions.*.type' => 'required|string|in:text,multiple-choice,checkbox',
                'questions.*.options.*' => 'nullable|required_if:questions.*.type,multiple-choice,checkbox|string',
            ]);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        }

        foreach ($request->questions as $question) {
            ScholarQuestion::create([
                'scholarship_id' => $request->scholarship_id,
                'question_text' => $question['question_text'],
                'type' => $question['type'],
                'options' => $question['options'],
            ]);
        }

        $notification = [
            'message' => "Scholarship Question(s) created Successfully!",
            'alert-type' => 'success'
        ];

        return redirect()->route('admin.scholarship.question.view')->with($notification);
    }

    /**
     * Display the specified resource.
     */
    public function show()
    {
        $scholarships = Scholarship::with('questions')->get();
        return view('admin.scholarshipQuestions.show', compact('scholarships'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        $question = ScholarQuestion::findOrFail($id);
        $scholarships = Scholarship::all();
        return view('admin.scholarshipQuestions.edit', compact('question', 'scholarships'));
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'scholarship_id' => 'required|exists:scholarships,id',
            'question_text' => 'required|string|max:255',
            'type' => 'required|string|in:text,multiple-choice,checkbox',
            'options' => 'nullable|array',
            'options.*' => 'nullable|required_if:type,multiple-choice,checkbox|string',
        ]);

        $question = ScholarQuestion::findOrFail($id);
        $question->update([
            'scholarship_id' => $request->scholarship_id,
            'question_text' => $request->question_text,
            'type' => $request->type,
            'options' => $request->options,
        ]);
        $notification = [
            'message' => "Scholarship Question Updated Successfully!",
            'alert-type' => 'success'
        ];

        return redirect()->route('admin.scholarship.question.show')->with($notification);
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {

        $question = ScholarQuestion::findOrFail($id);
        $question->delete();

        $notification = [
            'message' => "Scholarship Question Deleted Successfully!",
            'alert-type' => 'success'
        ];

        return redirect()->route('admin.scholarship.question.show')->with($notification);
    }
}
