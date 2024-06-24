<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EducationalProgramsStudentsModel extends Model
{
    use HasFactory;
    protected $table = 'educational_programs_students';
    protected $primaryKey = 'uid';

    protected $keyType = 'string';


    public function educationalProgram()
    {
        return $this->belongsTo(EducationalProgramsModel::class, 'educational_program_uid', 'uid');
    }
}
