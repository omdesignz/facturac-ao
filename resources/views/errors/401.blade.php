@extends('errors.layout')

@section('status', 'Erro 401 · Sem sessão')
@section('title', 'Precisa de iniciar sessão')
@section('message', 'Esta página só está disponível depois de entrar na sua conta.')

@section('actions')
    <a class="button" href="/login">Entrar</a>
@endsection
