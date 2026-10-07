<?php

/**
 * Woodnork Green Customer Feedback Form Configuration
 * 
 * This is the SINGLE SOURCE OF TRUTH for all survey questions.
 * To add, edit, or remove questions, modify this file only.
 * 
 * Question Types:
 * - 'rating': 1-5 scale with explanation
 * - 'yes_no': Yes/No toggle
 * - 'choice': Single selection from configured options
 * - 'text': Text input
 * - 'textarea': Multi-line text
 * 
 * Rating Scale:
 * 1 - Very Poor (Needs significant improvement)
 * 2 - Poor (Below expectations)
 * 3 - Average (Meets expectations)
 * 4 - Good (Above expectations)
 * 5 - Excellent (Exceeded expectations)
 */

return [
    'title' => 'Your feedback matters to us.',
    'description' => 'Thank you for choosing Woodnork Green. Please take a moment to tell us about your experience. Your feedback helps us understand what worked, what we can improve, and how we can serve you better. It only takes a minute.',
    'completion_title' => 'Thank you for your feedback.',
    'completion_message' => 'We appreciate your time and the opportunity to improve your experience with Woodnork Green.',
    'rating_scale_info' => [
        1 => 'Very Poor',
        2 => 'Poor',
        3 => 'Average',
        4 => 'Good',
        5 => 'Excellent',
    ],
    
    'sections' => [
        // Project experience questions
        [
            'id' => 'project_experience',
            'title' => 'Your Project Experience',
            'questions' => [
                [
                    'id' => 'communication_effectiveness',
                    'type' => 'rating',
                    'label' => 'How effective was our communication throughout the project?',
                    'required' => true,
                ],
                [
                    'id' => 'team_interaction',
                    'type' => 'rating',
                    'label' => 'How would you rate your interaction with our team?',
                    'required' => true,
                ],
                [
                    'id' => 'final_delivery_quality',
                    'type' => 'rating',
                    'label' => 'How would you rate the quality of our final delivery?',
                    'required' => true,
                ],
                [
                    'id' => 'delivered_as_expected',
                    'type' => 'choice',
                    'label' => 'Did we deliver what you expected, on time?',
                    'options' => ['Yes', 'Partly', 'No'],
                    'required' => true,
                ],
                [
                    'id' => 'work_again_recommend',
                    'type' => 'rating',
                    'label' => 'How likely are you to work with Woodnork Green again or recommend us to others?',
                    'required' => true,
                    'rating_labels' => [
                        1 => 'Very Unlikely',
                        2 => 'Unlikely',
                        3 => 'Not Sure',
                        4 => 'Likely',
                        5 => 'Very Likely',
                    ],
                ],
            ],
        ],

        // Optional open feedback
        [
            'id' => 'remarks',
            'title' => 'Remarks',
            'questions' => [
                [
                    'id' => 'remarks',
                    'type' => 'textarea',
                    'label' => 'Remarks (Optional)',
                    'placeholder' => 'Please share any comments about the ratings or responses you selected above.',
                    'required' => false,
                ],
            ],
        ],
    ],
];
