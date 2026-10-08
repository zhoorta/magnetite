<?php

it('shows the landing page', function () {
    $this->get(route('welcome'))
        ->assertOk()
        ->assertSee('The look is', false)
        ->assertSee('A theme is one file');
});
