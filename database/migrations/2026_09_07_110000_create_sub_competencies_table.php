<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sub-competencies a competency breaks down into: a bilingual name and an
 * optional bilingual description, nothing else. They are edited inline on the
 * competency's own form (add a row at a time), so they cascade with their
 * parent and are not a master of their own — no active flag, no type, no
 * separate screen.
 *
 * The name is unique inside its competency rather than globally: the parent's
 * name already carries the global uniqueness, and two competencies may well
 * break down into similarly named parts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sub_competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competency_id')->constrained()->cascadeOnDelete();
            $table->string('name_en');
            $table->string('name_id')->nullable();
            $table->text('description_en')->nullable();
            $table->text('description_id')->nullable();
            $table->timestamps();
            $table->unique(['competency_id', 'name_en'], 'sub_competencies_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_competencies');
    }
};
