@extends('errors.layout')

@section('status', 'Erro 404 · Não encontrado')
@section('title', 'Não encontrámos esta página')
@section('message', 'O endereço pode estar mal escrito, ou o documento que procura pode ter sido removido ou pertencer a outro espaço de trabalho.')

@section('actions')
    <a class="button" href="/dashboard">Voltar ao painel</a>
@endsection
