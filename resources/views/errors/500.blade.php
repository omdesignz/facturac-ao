@extends('errors.layout')

@section('status', 'Erro 500 · Falha interna')
@section('title', 'Algo correu mal do nosso lado')
@section('message', 'Não foi nada que tenha feito. A falha ficou registada e nenhuma factura é emitida ou entregue à AGT quando isto acontece. Tente de novo dentro de instantes.')

@section('actions')
    <a class="button" href="/dashboard">Voltar ao painel</a>
@endsection
