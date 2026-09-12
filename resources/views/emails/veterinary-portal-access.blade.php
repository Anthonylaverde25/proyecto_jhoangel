@component('mail::message')
# Acceso al portal veterinario

{{ $establishmentName }} le envía un enlace de acceso al portal veterinario.

**Profesional:** {{ $veterinarianName }}@if($licenseNumber) — M.P. {{ $licenseNumber }}@endif
@if($healthCenterName)
**Institución:** {{ $healthCenterName }}
@endif
@if($scopeDescription)
**Alcance:** {{ $scopeDescription }}
@endif
**Vence:** {{ $expiresAt }}

@if($senderNote)
> {{ $senderNote }}
@endif

@component('mail::button', ['url' => $accessUrl])
Abrir el portal veterinario
@endcomponent

Desde ahí puede firmar las actas de manga a su nombre y cargar los informes de laboratorio de las muestras que le correspondan.

@component('mail::panel')
Este enlace es personal y de un solo destinatario. **No vuelve a mostrarse ni puede recuperarse:** si lo pierde, solicite al establecimiento que emita uno nuevo. Vence automáticamente en la fecha indicada y puede ser revocado en cualquier momento.
@endcomponent

Si no esperaba este correo, ignórelo: sin abrir el enlace, el acceso nunca se activa.

Gracias,<br>
{{ $establishmentName }}

@component('mail::subcopy')
Si el botón no funciona, copie y pegue esta dirección en su navegador:
[{{ $accessUrl }}]({{ $accessUrl }})
@endcomponent
@endcomponent
