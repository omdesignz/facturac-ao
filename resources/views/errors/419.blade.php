@extends('errors.layout')

@section('status', 'Erro 419 · Sessão expirada')
@section('title', 'A sua sessão expirou')
@section('message', 'Por segurança, fechamos a sessão depois de algum tempo sem actividade. Entre outra vez e continue de onde estava.')

@section('actions')
    <a class="button" href="/login">Entrar outra vez</a>
@endsection
