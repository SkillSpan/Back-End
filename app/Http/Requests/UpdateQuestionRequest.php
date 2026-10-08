<?php

namespace App\Http\Requests;

/**
 * Validates an edited question from the admin "Questions" page.
 *
 * The panel's edit form posts the complete question — including the
 * specialization / career role / skill chain — so it is validated exactly
 * like a create. Inheriting keeps the two paths from drifting: a rule added
 * for creation (a new answer type, a stricter chain check) applies to edits
 * automatically, and an edit can never be a loophole around creation.
 */
class UpdateQuestionRequest extends StoreQuestionRequest {}
