<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateJournalsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->string('title', 45);
            $table->dateTime('date');
            $table->string('remark', 1000)->nullable(true);
            $table->string('ref', 45)->nullable(true);
            $table->double('uang_masuk')->nullable(true);
            $table->double('uang_keluar')->nullable(true);
            $table->integer('is_reimburse');
            $table->string('filebukti');
            $table->timestamps();
            $table->integer('chart_account_id');
            $table->integer('accounting_period_id');
            $table->integer('bank_account_id');
            $table->integer('project_id');
            $table->integer('user_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('journals');
    }
}