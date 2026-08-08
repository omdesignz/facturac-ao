@extends('errors.layout')

@section('status', 'Erro 403 · Sem permissão')
@section('title', 'Esta área não é para o seu acesso')
@section('message', 'A sua conta não tem permissão para ver ou alterar isto. Se acha que devia ter, peça ao responsável do espaço de trabalho para rever o seu papel.')

@section('actions')
    <a class="button" href="/dashboard">Voltar ao painel</a>
@endsection
