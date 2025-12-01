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
    'title' => 'Woodnork Green Customer Feedback Form',
    'description' => 'Thank you for choosing Woodnork Green! Your feedback helps us improve our products and services to better serve you. Please take a few minutes to share your experience with us.',
    'rating_scale_info' => [
        1 => 'Very Poor (Needs significant improvement)',
        2 => 'Poor (Below expectations)',
        3 => 'Average (Meets expectations)',
        4 => 'Good (Above expectations)',
        5 => 'Excellent (Exceeded expectations)',
    ],
    
    'sections' => [
        // Section 1: Overall Satisfaction
        [
            'id' => 'overall_satisfaction',
            'title' => 'Overall Satisfaction',
            'questions' => [
                [
                    'id' => 'overall_rating',
                    'type' => 'rating',
                    'label' => 'On a scale of 1 to 5, how satisfied are you with our products and services?',
                    'required' => true,
                ],
                [
                    'id' => 'respondent_info',
                    'type' => 'textarea',
                    'label' => 'Respondent Information',
                    'placeholder' => 'Please provide your name, company, or any information you would like to share',
                    'required' => false,
                ],
            ],
        ],

        // Section 2: Service Quality
        [
            'id' => 'service_quality',
            'title' => 'Service Quality',
            'description' => 'How would you rate the quality of our branding and event materials?',
            'questions' => [
                [
                    'id' => 'finishing',
                    'type' => 'rating',
                    'label' => 'Finishing',
                    'required' => true,
                    'has_remarks' => true,
                ],
                [
                    'id' => 'attention_to_detail',
                    'type' => 'rating',
                    'label' => 'Attention to Detail & Print Quality',
                    'required' => true,
                    'has_remarks' => true,
                ],
            ],
        ],

        // Section 3: Timeliness
        [
            'id' => 'timeliness',
            'title' => 'Timeliness',
            'questions' => [
                [
                    'id' => 'delivered_on_time',
                    'type' => 'yes_no',
                    'label' => 'Did we deliver your project within the agreed timeframe?',
                    'required' => true,
                ],
                [
                    'id' => 'delivery_condition',
                    'type' => 'rating',
                    'label' => 'Condition upon delivery',
                    'required' => true,
                    'has_remarks' => true,
                ],
            ],
        ],

        // Section 4: Communication
        [
            'id' => 'communication',
            'title' => 'Communication',
            'questions' => [
                [
                    'id' => 'communication_effectiveness',
                    'type' => 'rating',
                    'label' => 'How effective was our communication throughout the project?',
                    'required' => true,
                    'has_remarks' => true,
                ],
            ],
        ],

        // Section 5: Staff Interaction
        [
            'id' => 'staff_interaction',
            'title' => 'Staff Interaction',
            'description' => 'How would you describe your interactions with our team members?',
            'questions' => [
                [
                    'id' => 'installation_precision',
                    'type' => 'rating',
                    'label' => 'Details & Precision of Installation',
                    'required' => true,
                    'has_remarks' => true,
                ],
                [
                    'id' => 'work_efficiency',
                    'type' => 'rating',
                    'label' => 'Work process efficiency during set up',
                    'required' => true,
                    'has_remarks' => true,
                ],
                [
                    'id' => 'team_professionalism',
                    'type' => 'rating',
                    'label' => 'Team Professionalism',
                    'required' => true,
                    'has_remarks' => true,
                ],
                [
                    'id' => 'execution_confidence',
                    'type' => 'rating',
                    'label' => 'Confidence in Execution',
                    'required' => true,
                    'has_remarks' => true,
                ],
            ],
        ],

        // Section 6: Problem Resolution
        [
            'id' => 'problem_resolution',
            'title' => 'Problem Resolution',
            'questions' => [
                [
                    'id' => 'issue_resolution',
                    'type' => 'rating',
                    'label' => 'If you encountered any issues, how effectively were they resolved?',
                    'required' => true,
                    'has_remarks' => true,
                ],
            ],
        ],

        // Section 7: Sustainability
        [
            'id' => 'sustainability',
            'title' => 'Sustainability Practices',
            'questions' => [
                [
                    'id' => 'sustainability_importance',
                    'type' => 'rating',
                    'label' => 'How important is our commitment to sustainability in your decision to work with us?',
                    'required' => true,
                ],
            ],
        ],

        // Section 8: Recommendation
        [
            'id' => 'recommendation',
            'title' => 'Recommendation Likelihood',
            'questions' => [
                [
                    'id' => 'recommendation_likelihood',
                    'type' => 'rating',
                    'label' => 'How likely are you to recommend Woodnork Green to a colleague or friend?',
                    'required' => true,
                ],
            ],
        ],

        // Section 9: Improvements & Additional Comments
        [
            'id' => 'feedback',
            'title' => 'Future Improvements',
            'questions' => [
                [
                    'id' => 'improvement_suggestions',
                    'type' => 'textarea',
                    'label' => 'What suggestions do you have for improving our products or services?',
                    'placeholder' => 'Please share your ideas for how we can improve...',
                    'required' => false,
                ],
                [
                    'id' => 'additional_comments',
                    'type' => 'textarea',
                    'label' => 'Additional Comments',
                    'placeholder' => 'Is there anything else you would like to share about your experience with us?',
                    'required' => false,
                ],
            ],
        ],
    ],
];
