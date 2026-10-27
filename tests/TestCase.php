<?php
namespace Tests;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
abstract class TestCase extends BaseTestCase
{
 public function createApplication()
 {
  $app=parent::createApplication();
  if (!$app->environment('testing') || config('database.connections.pgsql.database') !== 'virtus_agenda_test' || config('database.connections.pgsql.username') !== 'virtus_agenda_test') {
   throw new \RuntimeException('Tests require the isolated Agenda test database and role.');
  }
  Http::preventStrayRequests();
  return $app;
 }
}
