<?php

namespace App\Http\Controllers\LearningObjects;

use App\Exceptions\OperationFailedException;
use App\Models\CallsModel;
use App\Models\CoursesModel;
use Illuminate\Routing\Controller as BaseController;
use App\Models\EducationalProgramsModel;
use App\Models\EducationalProgramTypesModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Logs\LogsController;
use App\Models\EducationalProgramTagsModel;
use App\Models\CategoriesModel;
use App\Models\CourseStatusesModel;
use App\Models\EducationalProgramStatusesModel;
use App\Models\EducationalsProgramsCategoriesModel;
use App\Models\EducationalProgramsStudentsModel;
use App\Models\UsersModel;
use App\Models\CoursesTagsModel;
use App\Models\EducationalProgramsStudentsDocumentsModel;
use App\Models\EmailNotificationsAutomaticModel;
use App\Models\GeneralNotificationsAutomaticModel;
use App\Models\GeneralNotificationsAutomaticUsersModel;
use League\Csv\Reader;

use App\Rules\NifNie;

class EducationalProgramsController extends BaseController
{
    public function index()
    {

        $calls = CallsModel::all()->toArray();
        $educational_program_types = EducationalProgramTypesModel::all()->toArray();
        $categories = CategoriesModel::with('parentCategory')->get();

        $variables_js = [
            "frontUrl" => env('FRONT_URL')
        ];

        return view(
            'learning_objects.educational_programs.index',
            [
                "page_name" => "Listado de programas formativos",
                "page_title" => "Listado de programas formativos",
                "resources" => [
                    "resources/js/learning_objects_module/educational_programs.js"
                ],
                "tabulator" => true,
                "calls" => $calls,
                "educational_program_types" => $educational_program_types,
                "tomselect" => true,
                "categories" => $categories,
                "coloris" => true,
                "variables_js" => $variables_js,
                "submenuselected" => "learning-objects-educational-programs",
            ]
        );
    }

    public function getEducationalPrograms(Request $request)
    {
        $size = $request->get('size', 1);
        $search = $request->get('search');
        $sort = $request->get('sort');

        $query = EducationalProgramsModel::join("educational_program_types as educational_program_type", "educational_program_type.uid", "=", "educational_programs.educational_program_type_uid", "left")
            ->join("calls", "educational_programs.call_uid", "=", "calls.uid", "left")
            ->join("educational_program_statuses", "educational_programs.educational_program_status_uid", "=", "educational_program_statuses.uid", "left");



        if ($search) {
            $query->where(function ($query) use ($search) {
                $query->where('educational_programs.name', 'LIKE', "%{$search}%")
                    ->orWhere('educational_programs.description', 'LIKE', "%{$search}%");
            });
        }

        if (isset($sort) && !empty($sort)) {
            foreach ($sort as $order) {
                $query->orderBy($order['field'], $order['dir']);
            }
        }

        $query->select("educational_programs.*", "educational_program_type.name as educational_program_type_name", "calls.name as call_name", 'educational_program_statuses.name as status_name', 'educational_program_statuses.code as status_code');
        $data = $query->paginate($size);

        return response()->json($data, 200);
    }

    private function checkNewStatus($action, $educationalProgram)
    {
        $statuses = EducationalProgramStatusesModel::whereIn('code', [
            'INTRODUCTION',
            'PENDING_PUBLICATION',
            'PENDING_APPROVAL'
        ])->get()->keyBy('code');


        if ($action == "draft") {
            $newEducationalProgramStatus = $statuses['INTRODUCTION'];
        } elseif ($action == "submit") {
            // Comprobamos si está permitido que el curso pase a estado de revisión
            if ($educationalProgram->educational_program_origin_uid && app("general_options")["necessary_approval_editions"]) {
                $newEducationalProgramStatus = $statuses["PENDING_APPROVAL"];
            } else {
                $newEducationalProgramStatus = $statuses["PENDING_PUBLICATION"];
            }
        }

        return $newEducationalProgramStatus;
    }

    /**
     * Crea una nueva convocatoria.
     *
     * @param  \Illuminate\Http\Request  $request Los datos de la nueva convocatoria.
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveEducationalProgram(Request $request)
    {

        $educational_program_uid = $request->input("educational_program_uid");

        if ($educational_program_uid) {
            $educational_program = EducationalProgramsModel::find($educational_program_uid);
            $isNew = false;
        } else {
            $educational_program = new EducationalProgramsModel();
            $educational_program_uid = generate_uuid();
            $educational_program->uid = $educational_program_uid;
            $isNew = true;
        }

        if ($educational_program->educational_program_origin_uid) {
            $errors = $this->validateEducationalProgramNewEdition($request);
        } else {
            $errors = $this->validateEducationalProgram($request);
        }

        if ($errors->any()) {
            return response()->json(['message' => 'Algunos campos son incorrectos', 'errors' => $errors], 400);
        }

        if (!$isNew && !in_array($educational_program->status->code, ["INTRODUCTION", "UNDER_CORRECTION_PUBLICATION", "UNDER_CORRECTION_APPROVAL"])) {
            throw new OperationFailedException('No puedes editar un programa formativo en este estado', 400);
        }

        $this->validateCoursesAddedEducationalProgram($request, $educational_program);

        $action = $request->input('action');

        $newStatus = $this->checkNewStatus($action, $educational_program);

        if ($newStatus) {
            $educational_program->educational_program_status_uid = $newStatus->uid;
        }

        DB::transaction(function () use ($request, &$isNew, $educational_program) {
            if ($educational_program->educational_program_origin_uid) {
                $this->fillEducationalProgramEdition($request, $educational_program);
            } else {
                $this->fillEducationalProgram($request, $educational_program);
            }

            $this->handleImageUpload($request, $educational_program);

            $educational_program->save();

            $validateStudentsRegistrations = $request->input("validate_student_registrations");

            if ($validateStudentsRegistrations) {
                $this->syncDocuments($request, $educational_program);
            } else {
                $educational_program->deleteDocuments();
            }

            $this->logAction($isNew);
        });

        return response()->json(['message' => $isNew ? 'Programa formativo añadido correctamente' : 'Programa formativo actualizado correctamente']);
    }

    private function validateEducationalProgramNewEdition($request)
    {
        $validateStudentsRegistrations = $request->input("validate_student_registrations");
        $cost = $request->input("cost");

        if ($validateStudentsRegistrations || ($cost && $cost > 0)) {
            $enrollingDates = true;
        } else {
            $enrollingDates = false;
        }

        $messages = $this->getValidationMessages($enrollingDates);

        $rules = [
            'validate_student_registrations' => 'boolean',
            'evaluation_criteria' => 'required_if:validate_student_registrations,1',
            'min_required_students' => 'nullable|integer',

            'inscription_start_date' => 'required|date',
            'inscription_finish_date' => 'required|date|after_or_equal:inscription_start_date',
        ];

        $validateStudentsRegistrations = $request->input("validate_student_registrations");
        $cost = $request->input("cost");

        if ($validateStudentsRegistrations || ($cost && $cost > 0)) {
            $rules['enrolling_start_date'] = 'required|date|after_or_equal:inscription_finish_date';
            $rules['enrolling_finish_date'] = 'required|date|after_or_equal:enrolling_start_date';

            $rules['realization_start_date'] = 'required|date|after_or_equal:enrolling_finish_date';
            $rules['realization_finish_date'] = 'required|date|after_or_equal:realization_start_date';
        } else {
            $rules['realization_start_date'] = 'required|date|after_or_equal:inscription_finish_date';
            $rules['realization_finish_date'] = 'required|date|after_or_equal:realization_start_date';
        }

        $validator = Validator::make($request->all(), $rules, $messages);

        $errorsValidator = $validator->errors();

        return $errorsValidator;
    }

    private function syncDocuments($request, $educational_program)
    {
        $documents = $request->input('documents');
        $documents = json_decode($documents, true);
        $educational_program->updateDocuments($documents);
    }

    private function syncItemsTags($request, $educational_program)
    {
        $current_tags = EducationalProgramTagsModel::where('educational_program_uid', $educational_program->uid)->pluck('tag')->toArray();

        $tags = $request->input('tags');
        $tags = json_decode($tags, true);

        // Identificar qué items son nuevos y cuáles deben ser eliminados
        $items_to_add = array_diff($tags, $current_tags);
        $items_to_delete = array_diff($current_tags, $tags);

        // Eliminar los items que ya no son necesarios
        EducationalProgramTagsModel::where('educational_program_uid', $educational_program->uid)->whereIn('tag', $items_to_delete)->delete();

        // Preparar el array para la inserción masiva de nuevos items
        $insertData = [];
        foreach ($items_to_add as $item) {
            $insertData[] = [
                'uid' => generate_uuid(),
                'educational_program_uid' => $educational_program->uid,
                'tag' => $item
            ];
        }

        // Insertar todos los nuevos items en una única operación de BD
        EducationalProgramTagsModel::insert($insertData);
    }

    private function syncCategories($request, $educationalProgram)
    {
        // Categorías
        $categories = $request->input('categories');
        $categories = json_decode($categories, true);
        $categories_bd = CategoriesModel::whereIn('uid', $categories)->get()->pluck('uid');

        EducationalsProgramsCategoriesModel::where('educational_program_uid', $educationalProgram->uid)->delete();
        $categories_to_sync = [];

        foreach ($categories_bd as $category_uid) {
            $categories_to_sync[] = [
                'uid' => generate_uuid(),
                'educational_program_uid' => $educationalProgram->uid,
                'category_uid' => $category_uid
            ];
        }

        $educationalProgram->categories()->sync($categories_to_sync);
    }

    private function handleImageUpload($request, $educational_program)
    {
        if ($request->file('image_path')) {
            $file = $request->file('image_path');
            $path = 'images/educational-programs-images';

            $destinationPath = public_path($path);

            $filename = add_timestamp_name_file($file);

            $file->move($destinationPath, $filename);

            $educational_program->image_path = $path . "/" . $filename;
        }
    }

    private function fillEducationalProgramEdition($request, $educational_program)
    {
        $baseFields = [
            "min_required_students", "inscription_start_date", "inscription_finish_date",
            "validate_student_registrations", "cost",
            "realization_start_date", "realization_finish_date", "featured_slider", "featured_slider"
        ];

        $conditionalFieldsDates = [
            "enrolling_start_date", "enrolling_finish_date"
        ];

        $conditionalRestFields = [
            "evaluation_criteria"
        ];

        $validateStudentsRegistrations = $request->input("validate_student_registrations");
        $cost = $request->input("cost");

        $fields = $baseFields;

        if ($validateStudentsRegistrations) {
            $fields = array_merge($fields, $conditionalFieldsDates, $conditionalRestFields);
        }

        if ($cost && $cost > 0) {
            $fields = array_merge($fields, $conditionalFieldsDates);
        }

        $educational_program->fill($request->only($fields));
        // Establecer a null los campos que no están en la lista de campos a actualizar
        $allFields = array_merge($fields, $conditionalFieldsDates, $conditionalRestFields);
        foreach ($allFields as $field) {
            if (!in_array($field, $fields)) {
                $educational_program->$field = null;
            }
        }
    }

    private function fillEducationalProgram($request, $educational_program)
    {
        $baseFields = [
            'name', 'description', 'educational_program_type_uid', 'call_uid',
            'inscription_start_date', 'inscription_finish_date', 'min_required_students', 'validate_student_registrations',
            'cost', 'featured_slider', 'featured_slider_title', 'featured_slider_description', 'featured_slider_color_font',
            'featured_slider_image_path', 'featured_main_carrousel', 'realization_start_date', 'realization_finish_date'
        ];

        $conditionalFieldsDates = ['enrolling_start_date', 'enrolling_finish_date'];
        $conditionalFieldsEvaluationCriteria = ['evaluation_criteria'];

        $validateStudentsRegistrations = $request->input("validate_student_registrations");
        $cost = $request->input("cost");

        $fields = $baseFields;
        if ($validateStudentsRegistrations || $cost && $cost > 0) {
            $fields = array_merge($fields, $conditionalFieldsDates);
        }

        if ($validateStudentsRegistrations) {
            $fields = array_merge($fields, $conditionalFieldsEvaluationCriteria);
        }

        $educational_program->fill($request->only($fields));

        if ($request->hasFile('featured_slider_image_path')) {
            $educational_program->featured_slider_image_path = saveFile($request->file('featured_slider_image_path'), 'images/carrousel-images', null, true);
        }

        // Ponemos a null los campos que no corresponden
        $allFields = array_merge($baseFields, $conditionalFieldsDates, $conditionalFieldsEvaluationCriteria);
        foreach (array_diff($allFields, $fields) as $field) {
            $educational_program->$field = null;
        }

        $educational_program->save();

        $this->handleCourses($request, $educational_program);
        $this->syncItemsTags($request, $educational_program);
        $this->syncCategories($request, $educational_program);
    }

    private function handleCourses($request, $educational_program)
    {
        $statusesCourses = CourseStatusesModel::whereIn('code', [
            'READY_ADD_EDUCATIONAL_PROGRAM',
            'ADDED_EDUCATIONAL_PROGRAM'
        ])
            ->get()
            ->keyBy('code');

        $coursesUidsToAdd = $request->input('courses');

        // Cursos que se quitan del programa formativo
        $coursesToRemoveEducationalProgram = $educational_program->courses->filter(function ($course) use ($coursesUidsToAdd) {
            return !in_array($course->uid, $coursesUidsToAdd);
        });

        $coursesUidsToRemoveEducationalProgram = $coursesToRemoveEducationalProgram->pluck('uid');

        CoursesModel::whereIn('uid', $coursesUidsToRemoveEducationalProgram)->update([
            'educational_program_uid' => null,
            'course_status_uid' => $statusesCourses["READY_ADD_EDUCATIONAL_PROGRAM"]->uid
        ]);

        CoursesModel::whereIn('uid', $coursesUidsToAdd)->update([
            'educational_program_uid' => $educational_program->uid,
            'course_status_uid' => $statusesCourses["ADDED_EDUCATIONAL_PROGRAM"]->uid
        ]);
    }

    private function logAction($isNew)
    {
        $logMessage = $isNew ? 'Programa formativo añadido' : 'Programa formativo actualizado';
        LogsController::createLog($logMessage, 'Programas educativos', auth()->user()->uid);
    }

    private function getValidationMessages($enrollingDates)
    {
        $messages = [
            'name.required' => 'El nombre es obligatorio',
            'name.max' => 'El nombre no puede tener más de 255 caracteres',
            'educational_program_type_uid.required' => 'El tipo de programa educativo es obligatorio',
            'inscription_start_date.required' => 'La fecha de inicio de inscripción es obligatoria',
            'inscription_finish_date.required' => 'La fecha de fin de inscripción es obligatoria',
            'inscription_start_date.after_or_equal' => 'La fecha de inicio de inscripción no puede ser anterior a la fecha y hora actual.',
            'inscription_finish_date.after_or_equal' => 'La fecha de fin de inscripción no puede ser anterior a la fecha de inicio de inscripción.',
            'enrolling_start_date.required' => 'La fecha de inicio de matriculación es obligatoria',
            'enrolling_finish_date.required' => 'La fecha de fin de matriculación es obligatoria',
            'enrolling_start_date.after_or_equal' => 'La fecha de inicio de matriculación no puede ser anterior a la fecha de fin de inscripción.',
            'enrolling_finish_date.after_or_equal' => 'La fecha de fin de matriculación no puede ser anterior a la fecha de inicio de matriculación.',
            'min_required_students.integer' => 'El número mínimo de estudiantes debe ser un número entero',
            'evaluation_criteria.required_if' => 'Los criterios de evaluación son obligatorios si se valida la inscripción de estudiantes',
            'realization_start_date.required' => 'La fecha de inicio de realización es obligatoria',
            'realization_finish_date.required' => 'La fecha de fin de realización es obligatoria',

            'realization_finish_date.after_or_equal' => 'La fecha de fin de realización no puede ser anterior a la fecha de inicio de realización',
        ];

        if ($enrollingDates) {
            $messages["realization_start_date.after_or_equal"] = "La fecha de inicio de realización no puede ser anterior a la fecha de fin de matriculación";
        } else {
            $messages["realization_start_date.after_or_equal"] = "La fecha de inicio de realización no puede ser anterior a la fecha de fin de inscripción";
        }

        return $messages;
    }

    private function validateEducationalProgram($request)
    {

        $validateStudentsRegistrations = $request->input("validate_student_registrations");
        $cost = $request->input("cost");

        if ($validateStudentsRegistrations || ($cost && $cost > 0)) {
            $enrollingDates = true;
        } else {
            $enrollingDates = false;
        }

        $messages = $this->getValidationMessages($enrollingDates);

        $rules = [
            'name' => 'required|max:255',
            'educational_program_type_uid' => 'required',
            'inscription_start_date' => 'required|date',
            'inscription_finish_date' => 'required|date|after_or_equal:inscription_start_date',
            'min_required_students' => 'nullable|integer',
            'validate_student_registrations' => 'boolean',
            'evaluation_criteria' => 'required_if:validate_student_registrations,1',
            'courses' => 'required|array',
        ];

        $this->addRulesDates($request, $rules);

        $validator = Validator::make($request->all(), $rules, $messages);

        $errorsValidator = $validator->errors();

        return $errorsValidator;
    }

    /**
     * Añade las reglas de validación de fechas a las reglas de validación.
     * Si el curso tiene validación de estudiantes o un coste, se solicita plazo de matriculación.
     * Si no, se valida simplemente el plazo de realización
     */
    private function addRulesDates($request, &$rules)
    {
        $validateStudentsRegistrations = $request->input("validate_student_registrations");
        $cost = $request->input("cost");
        // Si se valida la inscripción de estudiantes o el curso tiene un coste, se solicita plazo de matriculación
        if ($validateStudentsRegistrations || ($cost && $cost > 0)) {
            $rules['enrolling_start_date'] = 'required|date|after_or_equal:inscription_finish_date';
            $rules['enrolling_finish_date'] = 'required|date|after_or_equal:enrolling_start_date';

            $rules['realization_start_date'] = 'required|date|after_or_equal:enrolling_finish_date';
            $rules['realization_finish_date'] = 'required|date|after_or_equal:realization_start_date';
        } else {
            $rules['realization_start_date'] = 'required|date|after_or_equal:inscription_finish_date';
            $rules['realization_finish_date'] = 'required|date|after_or_equal:realization_start_date';
        }
    }

    /**
     * Valida que los cursos añadidos a un programa formativo estén marcados que pertenecen
     * a un programa formativo y tienen el estado READY_ADD_EDUCATIONAL_PROGRAM
     */
    private function validateCoursesAddedEducationalProgram($request, $educational_program)
    {
        $courses = $request->input('courses');

        if (empty($courses)) return;

        $coursesBd = CoursesModel::whereIn('uid', $courses)->with('status')->get();

        // Validamos que los cursos pertenezcan a un programa formativo
        $coursesNotBelongingEducationalProgram = $coursesBd->filter(function ($course) {
            return !$course->belongs_to_educational_program;
        });

        if ($coursesNotBelongingEducationalProgram->count()) {
            throw new OperationFailedException(
                'Algún curso no pertenece a un programa formativo',
                400
            );
        }

        // Validamos las fechas de realización de los cursos.
        // Los cursos deben tener un período de realización que esté entre las fechas de realización del programa formativo
        $realization_start_date = $request->input('realization_start_date');
        $realization_finish_date = $request->input('realization_finish_date');

        $coursesNotBetweenRealizationDate = $coursesBd->filter(function ($course) use ($realization_start_date, $realization_finish_date) {
            if ($course->realization_start_date && $course->realization_finish_date) {
                return $course->realization_start_date < $realization_start_date || $course->realization_finish_date > $realization_finish_date;
            }
        });

        if ($coursesNotBetweenRealizationDate->count()) {
            throw new OperationFailedException(
                'Algunos cursos no están entre las fechas de realización del programa formativo',
                400
            );
        }

        $coursesBelongingOtherEducationalPrograms = $coursesBd->filter(function ($course) use ($educational_program) {
            return $course->educational_program_uid && $course->educational_program_uid !== $educational_program->uid;
        });

        if ($coursesBelongingOtherEducationalPrograms->count()) {
            throw new OperationFailedException(
                'Algunos cursos pertenecen a otro programa formativo',
                400
            );
        }

        $newCourses = $coursesBd->filter(function ($course) use ($educational_program) {
            return $course->educational_program_uid !== $educational_program->uid;
        });

        $coursesNotHavingStatusReadyAddEducationalProgram = $newCourses->filter(function ($course) {
            return $course->status->code !== 'READY_ADD_EDUCATIONAL_PROGRAM';
        });

        if ($coursesNotHavingStatusReadyAddEducationalProgram->count()) {
            throw new OperationFailedException(
                'Algunos cursos no tienen el estado correcto para ser añadidos a un programa formativo',
                400
            );
        }
    }

    /**
     * Elimina una convocatoria específica.
     *
     * @param  string $call_uid El UID de la convocatoria.
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteEducationalPrograms(Request $request)
    {
        $uids = $request->input('uids');

        DB::transaction(function () use ($uids) {
            EducationalProgramsModel::destroy($uids);
            LogsController::createLog("Eliminación de programas educativos", 'Programas educativos', auth()->user()->uid);
        });

        return response()->json(['message' => 'Programas formativos eliminados correctamente']);
    }

    /**
     * Obtiene un programa formativo por uid
     */

    public function getEducationalProgram($educational_program_uid)
    {

        if (!$educational_program_uid) {
            return response()->json(['message' => env('ERROR_MESSAGE')], 400);
        }

        $educational_program = EducationalProgramsModel::where('uid', $educational_program_uid)->with(['courses', 'status', 'tags', 'categories', 'EducationalProgramDocuments'])->first();

        if (!$educational_program) {
            return response()->json(['message' => 'El programa formativo no existe'], 406);
        }

        return response()->json($educational_program, 200);
    }

    public function searchCoursesWithoutEducationalProgram($search)
    {
        $courses_query = CoursesModel::with('status')->where('belongs_to_educational_program', true)
            ->whereHas('status', function ($query) {
                $query->where('code', 'READY_ADD_EDUCATIONAL_PROGRAM');
            });

        if ($search) {
            $courses_query->where(function ($query) use ($search) {
                $query->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        $courses = $courses_query->get();

        return response()->json($courses, 200);
    }

    /**
     * Cambia el estado a un array de programas formativos
     */
    public function changeStatusesEducationalPrograms(Request $request)
    {

        if (!auth()->user()->hasAnyRole(["ADMINISTRATOR", "MANAGEMENT"])) {
            throw new OperationFailedException('No tienes permisos para realizar esta acción', 403);
        }

        $changesEducationalProgramsStatuses = $request->input('changesEducationalProgramsStatuses');

        if (!$changesEducationalProgramsStatuses) {
            return response()->json(['message' => 'No se han enviado los datos correctamente'], 406);
        }

        // Obtenemos los cursos de la base de datos
        $educational_programs_bd = EducationalProgramsModel::whereIn('uid', array_column($changesEducationalProgramsStatuses, "uid"))->with('status')->get();

        // Excluímos los estados a los que no se pueden cambiar manualmente.
        $statuses_educational_programs = EducationalProgramStatusesModel::whereNotIn('code', ['INSCRIPTION', 'DEVELOPMENT', 'PENDING_INSCRIPTION', 'FINISHED'])->get();

        // Aquí iremos almacenando los datos de los cursos que se van a actualizar
        $updated_educational_programs_data = [];

        // Recorremos los cursos que nos vienen en el request y los comparamos con los de la base de datos
        foreach ($changesEducationalProgramsStatuses as $changeEducationalProgramStatus) {

            // Obtenemos el curso de la base de datos
            $educational_program_bd = findOneInArrayOfObjects($educational_programs_bd, 'uid', $changeEducationalProgramStatus['uid']);

            // Si no existe el curso en la base de datos, devolvemos un error
            if (!$educational_program_bd) {
                return response()->json(['message' => 'Uno de los cursos no existe'], 406);
            }

            // Le cambiamos a cada curso el estado que nos viene en el request
            $status_bd = findOneInArrayOfObjects($statuses_educational_programs, 'code', $changeEducationalProgramStatus['status']);

            if (!$status_bd) {
                return response()->json(['message' => 'El estado es incorrecto'], 406);
            }

            $updated_educational_programs_data[] = [
                'uid' => $educational_program_bd['uid'],
                'educational_program_status_uid' => $status_bd['uid'],
                'reason' => $changeEducationalProgramStatus['reason'] ?? null
            ];
        }

        DB::transaction(function () use ($updated_educational_programs_data) {
            // Guardamos en la base de datos los cambios
            foreach ($updated_educational_programs_data as $data) {

                EducationalProgramsModel::updateOrInsert(
                    ['uid' => $data['uid']],
                    [
                        'educational_program_status_uid' => $data['educational_program_status_uid'],
                        'status_reason' => $data['reason']
                    ]
                );
            }

            LogsController::createLog('Cambio de estado de programa formativo', 'Cursos', auth()->user()->uid);
        });

        return response()->json(['message' => 'Se han actualizado los estados de los programas formativos correctamente'], 200);
    }


    public function getEducationalProgramStudents(Request $request, $educational_program_uid)
    {
        $size = $request->get('size', 1);
        $search = $request->get('search');
        $sort = $request->get('sort');

        $educational_program = EducationalProgramsModel::where('uid', $educational_program_uid)->first();

        $query = $educational_program->students()->with(
            [
                'educationalProgramDocuments' => function ($query) use ($educational_program_uid) {
                    $query->whereHas('educationalProgramDocument', function ($query) use ($educational_program_uid) {
                        $query->where('educational_program_uid', $educational_program_uid);
                    });
                },
                'educationalProgramDocuments.educationalProgramDocument'
            ]
        );

        if ($search) {
            $query->where(function ($subQuery) use ($search) {
                $subQuery->whereRaw("concat(first_name, ' ', last_name) like ?", ["%$search%"])
                    ->orWhere('nif', 'like', "%$search%");
            });
        }

        if (isset($sort) && !empty($sort)) {
            foreach ($sort as $order) {
                if ($order['field'] == 'acceptance_status') {
                    $query->join('educational_programs_students', function ($join) use ($educational_program_uid) {
                        $join->on('users.uid', '=', 'educational_programs_students.user_uid')
                            ->where('educational_programs_students.educational_program_uid', '=', $educational_program_uid);
                    })->orderBy('educational_programs_students.acceptance_status', $order['dir']);
                } else {
                    $query->orderBy($order['field'], $order['dir']);
                }
            }
        }

        // Aplicar paginación
        $students = $query->paginate($size);

        return response()->json($students, 200);
    }

    public function enrollStudents(Request $request)
    {

        $users = $request->get('usersToEnroll');

        $usersenrolled = false;

        foreach ($users as $user) {

            $existingEnrollment = EducationalProgramsStudentsModel::where('educational_program_uid', $request->get('EducationalProgramUid'))
                ->where('user_uid', $user)
                ->first();

            if ($existingEnrollment) {
                $usersenrolled = true;
                continue;
            }

            $enroll = new EducationalProgramsStudentsModel();
            $enroll->uid = generate_uuid();
            $enroll->educational_program_uid = $request->get('EducationalProgramUid');
            $enroll->user_uid = $user;
            $enroll->calification_type = "NUMERIC";
            $enroll->acceptance_status = 'PENDING';
            $messageLog = "Alumno añadido a programa formativo";

            DB::transaction(function () use ($enroll, $messageLog) {
                $enroll->save();
                LogsController::createLog($messageLog, 'Programas formativos', auth()->user()->uid);
            });
        }

        $message = "Alumnos añadidos al programa formativo";

        if ($usersenrolled == true) {
            $message = "Alumnos añadidos al programa formativo. Los ya registrados no se han añadido.";
        }

        return response()->json(['message' => $message], 200);
    }

    public function changeStatusInscriptionsEducationalProgram(Request $request) {

            $selectedEducationalProgramStudents = $request->input('uids');
            $status = $request->input('status');

            $educationalProgramsStudents = EducationalProgramsStudentsModel::whereIn('uid', $selectedEducationalProgramStudents)
                ->with('educationalProgram')
                ->get();

            DB::transaction(function () use ($educationalProgramsStudents, $status) {

                foreach ($educationalProgramsStudents as $courseStudent) {
                    $courseStudent->acceptance_status = $status;
                    $courseStudent->save();

                    $this->saveGeneralNotificationAutomatic($courseStudent->educationalProgram, $status, $courseStudent->user_uid);
                    $this->saveEmailNotificationAutomatic($courseStudent->educationalProgram, $status, $courseStudent->user_uid);
                }

                LogsController::createLog('Cambio de estado de inscripciones de programa formativo', 'Programas formativos', auth()->user()->uid);
            });

            return response()->json(['message' => 'Estados de inscripciones cambiados correctamente'], 200);
    }

    private function saveGeneralNotificationAutomatic($educationalProgram, $status, $userUid)
    {
        $generalNotificationAutomatic = new GeneralNotificationsAutomaticModel();
        $generalNotificationAutomaticUid = generate_uuid();
        $generalNotificationAutomatic->uid = $generalNotificationAutomaticUid;

        if ($status == "ACCEPTED") {
            $generalNotificationAutomatic->title = "Inscripción a programa educativo aceptada";
            $generalNotificationAutomatic->description = "Tu inscripción en el programa educativo " . $educationalProgram->name . " ha sido aceptada";
        } else {
            $generalNotificationAutomatic->title = "Inscripción a programa educativo rechazada";
            $generalNotificationAutomatic->description = "Tu inscripción en el programa educativo " . $educationalProgram->name . " ha sido rechazada";
        }

        $generalNotificationAutomatic->entity = "educational_program";
        $generalNotificationAutomatic->entity_uid = $educationalProgram->uid;
        $generalNotificationAutomatic->created_at = now();
        $generalNotificationAutomatic->save();

        $generalNotificationAutomaticUser = new GeneralNotificationsAutomaticUsersModel();
        $generalNotificationAutomaticUser->uid = generate_uuid();
        $generalNotificationAutomaticUser->general_notifications_automatic_uid = $generalNotificationAutomaticUid;
        $generalNotificationAutomaticUser->user_uid = $userUid;
        $generalNotificationAutomaticUser->save();
    }

    private function saveEmailNotificationAutomatic($educationalProgram, $status, $userUid)
    {
        $emailNotificationAutomatic = new EmailNotificationsAutomaticModel();
        $emailNotificationAutomatic->uid = generate_uuid();

        $emailParameters = [
            'educational_program_title' => $educationalProgram->title,
        ];

        if ($status == "ACCEPTED") {
            $emailNotificationAutomatic->subject = "Inscripción a programa educativo aceptada";
            $emailParameters["status"] = "ACCEPTED";
        } else {
            $emailNotificationAutomatic->subject = "Inscripción a programa educativo rechazada";
            $emailParameters["status"] = "REJECTED";
        }

        $emailNotificationAutomatic->template = "educational_program_inscription_status";
        $emailNotificationAutomatic->parameters = json_encode($emailParameters);
        $emailNotificationAutomatic->user_uid = $userUid;

        $emailNotificationAutomatic->save();
    }

    public function enrollStudentsCsv(Request $request)
    {

        $file = $request->file('attachment');
        $educational_program_uid = $request->get('educational_program_uid');

        $reader = Reader::createFromPath($file->path());

        $user = false;

        foreach ($reader as $key => $row) {

            if ($key > 0) {

                $validatorNif = Validator::make(
                    ['tu_dato' => $row[2]],
                    ['tu_dato' => [new NifNie]] // Aplicar la regla NifNie al dato
                );
                if ($validatorNif->fails()) {
                    continue;
                }
                $reglas = [
                    'correo' => 'email', // La regla 'email' valida que sea un correo electrónico válido
                ];
                $validatorEmail = Validator::make(['correo' => $row[3]], $reglas);

                if ($validatorEmail->fails()) {
                    continue;
                }

                $existingUser = UsersModel::where('email', $row[3])
                    ->first();



                if ($existingUser) {

                    $this->enrollUserCsv($row, $existingUser->uid, $educational_program_uid);
                } else {

                    $this->singUpUser($row, $educational_program_uid);
                }
            }
        }

        $message = "Alumnos añadidos al programa formativo. Los ya registrados no se han añadido.";

        return response()->json(['message' => $message], 200);
    }

    public function enrollUserCsv($row, $user_uid, $educational_program_uid)
    {

        $usersenrolled = false;

        $existingEnrollment = EducationalProgramsStudentsModel::where('course_uid', $educational_program_uid)
            ->where('user_uid', $user_uid)
            ->first();

        if (!$existingEnrollment) {

            $enroll = new EducationalProgramsStudentsModel();
            $enroll->uid = generate_uuid();
            $enroll->course_uid = $educational_program_uid;
            $enroll->user_uid = $user_uid;
            $enroll->calification_type = "NUMERIC";
            $enroll->acceptance_status = 'PENDING';
            $messageLog = "Alumno añadido a programa formativo";

            DB::transaction(function () use ($enroll, $messageLog) {
                $enroll->save();
                LogsController::createLog($messageLog, 'Programas formativos', auth()->user()->uid);
            });
        }
    }

    public function singUpUser($row, $educational_program_uid)
    {

        $newUserUid = generate_uuid();

        $newUser = new UsersModel();
        $newUser->uid = $newUserUid;
        $newUser->first_name = $row[0];
        $newUser->last_name = $row[1];
        $newUser->nif = $row[2];
        $newUser->email = $row[3];


        $messageLog = "Alumno dado de alta";

        DB::transaction(function () use ($newUser, $messageLog) {
            $newUser->save();
            LogsController::createLog($messageLog, 'Programa formativo', auth()->user()->uid);
        });

        $this->enrollUserCsv($row, $newUserUid, $educational_program_uid);
    }

    public function editionOrDuplicateEducationalProgram(Request $request)
    {

        $educational_program_uid = $request->input("educationalProgramUid");
        $action = $request->input('action');

        if (!in_array($action, ["edition", "duplication"])) throw new OperationFailedException('Acción no permitida', 400);

        $educational_program_bd = EducationalProgramsModel::where('uid', $educational_program_uid)->with(['tags', 'categories', 'EducationalProgramDocuments'])->first();

        if (!$educational_program_bd) return response()->json(['message' => 'El programa formativo no existe'], 406);

        $new_educational_program = $educational_program_bd->replicate();

        $concatName = $action === "edition" ? "(nueva edición)" : "(copia)";
        $new_educational_program->name = $new_educational_program->name . " " . $concatName;

        if ($action == "edition") {
            $new_educational_program->educational_program_origin_uid = $educational_program_uid;
        }

        $introduction_status = EducationalProgramStatusesModel::where('code', 'INTRODUCTION')->first();
        $new_educational_program->educational_program_status_uid = $introduction_status->uid;

        DB::transaction(function () use ($new_educational_program, $educational_program_bd, $educational_program_uid, $action) {
            $new_educational_program_uid = generate_uuid();
            $new_educational_program->uid = $new_educational_program_uid;

            $new_educational_program->save();

            $this->duplicateEducationalProgramDocuments($educational_program_bd, $new_educational_program_uid);

            $courses = CoursesModel::where('educational_program_uid', $educational_program_uid)->get();

            foreach ($courses as $course) {

                $this->duplicateCourse($course->uid, $new_educational_program_uid);
            }

            $this->duplicateEducationalProgramTags($educational_program_bd, $new_educational_program_uid);

            $this->duplicateEducationalProgramsCategories($educational_program_bd, $new_educational_program_uid, $new_educational_program);

            if ($action === "edition") $logMessage = 'Creación de edición de programa formativo';
            else $logMessage = 'Duplicación de programa formativo';

            LogsController::createLog($logMessage, 'Programas formativos', auth()->user()->uid);
        }, 5);
        return response()->json(['message' => 'Programa formativo duplicado correctamente'], 200);
    }

    private function duplicateCourse($course_uid, $new_educational_program_uid)
    {
        $course_bd = CoursesModel::where('uid', $course_uid)->with(['teachers', 'tags', 'categories'])->first();

        if (!$course_bd) return response()->json(['message' => 'El curso no existe'], 406);

        $new_course = $course_bd->replicate();
        $new_course->title = $new_course->title;
        $new_course->educational_program_uid = $new_educational_program_uid;

        $introduction_status = CourseStatusesModel::where('code', 'ADDED_EDUCATIONAL_PROGRAM')->first();
        $new_course->course_status_uid = $introduction_status->uid;
        $new_course->belongs_to_educational_program = 1;

        $new_course_uid = generate_uuid();
        $new_course->uid = $new_course_uid;

        $new_course->save();

        $this->duplicateCourseTeachers($course_bd, $new_course_uid, $new_course);

        $this->duplicateCourseTags($course_bd, $new_course_uid);

        $this->duplicateCourseCategories($course_bd, $new_course_uid, $new_course);
    }
    private function duplicateEducationalProgramTags($educational_program_bd, $new_educational_program_uid)
    {
        $tags = $educational_program_bd->tags->pluck('tag')->toArray();
        $tags_to_add = [];
        foreach ($tags as $tag) {
            $tags_to_add[] = [
                'uid' => generate_uuid(),
                'educational_program_uid' => $new_educational_program_uid,
                'tag' => $tag,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        EducationalProgramTagsModel::insert($tags_to_add);
    }

    private function duplicateEducationalProgramsCategories($educational_program_bd, $new_educational_program_uid, $new_educational_program)
    {
        $categories = $educational_program_bd->categories->pluck('uid')->toArray();
        $categories_to_sync = [];
        foreach ($categories as $category_uid) {
            $categories_to_sync[] = [
                'uid' => generate_uuid(),
                'educational_program_uid' => $new_educational_program_uid,
                'category_uid' => $category_uid
            ];
        }
        $new_educational_program->categories()->sync($categories_to_sync);
    }
    private function duplicateCourseTeachers($course_bd, $new_course_uid, $new_course)
    {
        $teachers = $course_bd->teachers->pluck('uid')->toArray();
        $teachers_to_sync = [];

        foreach ($teachers as $teacher_uid) {
            $teachers_to_sync[$teacher_uid] = [
                'uid' => generate_uuid(),
                'course_uid' => $new_course_uid,
                'user_uid' => $teacher_uid
            ];
        }
        $new_course->teachers()->sync($teachers_to_sync);
    }
    private function duplicateCourseTags($course_bd, $new_course_uid)
    {
        $tags = $course_bd->tags->pluck('tag')->toArray();
        $tags_to_add = [];
        foreach ($tags as $tag) {
            $tags_to_add[] = [
                'uid' => generate_uuid(),
                'course_uid' => $new_course_uid,
                'tag' => $tag,
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
        CoursesTagsModel::insert($tags_to_add);
    }
    private function duplicateCourseCategories($course_bd, $new_course_uid, $new_course)
    {
        $categories = $course_bd->categories->pluck('uid')->toArray();
        $categories_to_sync = [];
        foreach ($categories as $category_uid) {
            $categories_to_sync[] = [
                'uid' => generate_uuid(),
                'course_uid' => $new_course_uid,
                'category_uid' => $category_uid
            ];
        }
        $new_course->categories()->sync($categories_to_sync);
    }

    private function duplicateEducationalProgramDocuments($educational_program_bd, $new_educational_program_uid)
    {
        foreach ($educational_program_bd->EducationalProgramDocuments as $document) {
            $newEducationalProgramDocument = $document->replicate();
            $newEducationalProgramDocument->uid = generate_uuid();
            $newEducationalProgramDocument->educational_program_uid = $new_educational_program_uid;
            $newEducationalProgramDocument->save();
        }
    }

    public function downloadDocumentStudent(Request $request)
    {
        $uidDocument = $request->get('uidDocument');
        $document = EducationalProgramsStudentsDocumentsModel::where('uid', $uidDocument)->first();
        return response()->download(storage_path($document->document_path));
    }
}
