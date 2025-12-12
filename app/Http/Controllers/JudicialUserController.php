<?php

namespace App\Http\Controllers;

use App\Models\JudicialUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class JudicialUserController extends Controller
{
    public function index()
    {
        $judicialUsers = Auth::user()->judicialUsers;
        return view('judicial-users.index', compact('judicialUsers'));
    }

    public function create()
    {
        return view('judicial-users.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_login' => 'required|string|max:255',
            'system_name' => 'required|string|max:255',
        ]);

        Auth::user()->judicialUsers()->create($validated);

        return redirect()->route('judicial-users.index')
            ->with('success', 'Usuário judicial cadastrado com sucesso!');
    }

    public function show(JudicialUser $judicialUser)
    {
        $this->authorize('view', $judicialUser);
        return view('judicial-users.show', compact('judicialUser'));
    }

    public function edit(JudicialUser $judicialUser)
    {
        $this->authorize('update', $judicialUser);
        return view('judicial-users.edit', compact('judicialUser'));
    }

    public function update(Request $request, JudicialUser $judicialUser)
    {
        $this->authorize('update', $judicialUser);

        $validated = $request->validate([
            'user_login' => 'required|string|max:255',
            'system_name' => 'required|string|max:255',
        ]);

        $judicialUser->update($validated);

        return redirect()->route('judicial-users.index')
            ->with('success', 'Usuário judicial atualizado com sucesso!');
    }

    public function destroy(JudicialUser $judicialUser)
    {
        $this->authorize('delete', $judicialUser);
        $judicialUser->delete();

        return redirect()->route('judicial-users.index')
            ->with('success', 'Usuário judicial removido com sucesso!');
    }
}
