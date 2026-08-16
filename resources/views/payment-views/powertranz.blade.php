@extends('payment-views.layouts.master')

@section('content')
    <div class="text-center"><h3>{{ translate('Please wait, do not refresh this page...') }}</h3></div>
    <div class="d-flex justify-content-center">
        {!! $iframe !!}
    </div>
@endsection
