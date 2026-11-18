<?php

use App\Domain\Generation\Enums\GenerationStatus as Status;

it('only moves forward through the provider lifecycle', function (Status $from, Status $to, bool $allowed) {
    expect($from->canAdvanceTo($to))->toBe($allowed);
})->with([
    [Status::PENDING, Status::QUEUED, true],
    [Status::PENDING, Status::PROCESSING, true],
    [Status::PENDING, Status::SUCCEEDED, true],
    [Status::QUEUED, Status::PROCESSING, true],
    [Status::QUEUED, Status::FAILED, true],
    [Status::PROCESSING, Status::SUCCEEDED, true],
    [Status::QUEUED, Status::QUEUED, false],
    [Status::PROCESSING, Status::QUEUED, false],
    // Final states never change, whatever arrives later.
    [Status::SUCCEEDED, Status::PROCESSING, false],
    [Status::SUCCEEDED, Status::FAILED, false],
    [Status::FAILED, Status::SUCCEEDED, false],
    [Status::FAILED, Status::QUEUED, false],
    // "Rejected" is only our inference; Pixelforge's own evidence wins.
    [Status::REJECTED, Status::QUEUED, true],
    [Status::REJECTED, Status::SUCCEEDED, true],
]);

it('maps only the four statuses Pixelforge reports', function () {
    expect(Status::fromProvider('succeeded'))->toBe(Status::SUCCEEDED);

    Status::fromProvider('rejected');
})->throws(InvalidArgumentException::class);
