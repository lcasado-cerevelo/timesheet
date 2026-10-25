import React from 'react';
import { Platform, Pressable, StyleSheet, Text, TextInput, View, ViewStyle, TextStyle, Modal, ScrollView } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useT } from './i18n';
import { useAuth } from './store/auth';
import { isValidHM, nowHM, to12h } from './lib/time';
import DateTimePicker from '@react-native-community/datetimepicker';

export const C = { bg: '#ececec', card: '#ffffff', text: '#1f2933', muted: '#6b7280', line: '#d9d9d9', danger: '#b00020', warn: '#b26a00', ok: '#1b7f3b' };

/** Colores de la marca de la compañía (vienen del servidor; se cambian desde Configuración > Marca). */
export function useTheme() {
  const brand = useAuth((s) => s.brand);
  return { primary: brand.color_primary, accent: brand.color_accent, brand };
}

export function Btn(props: { title: string; onPress: () => void; kind?: 'pri' | 'acc' | 'ghost' | 'danger'; disabled?: boolean; small?: boolean; style?: ViewStyle; testID?: string }) {
  const th = useTheme();
  const kind = props.kind ?? 'acc';
  const bg = kind === 'pri' ? th.primary : kind === 'acc' ? th.accent : '#fff';
  const fg = kind === 'ghost' ? th.primary : kind === 'danger' ? C.danger : '#fff';
  const border = kind === 'ghost' ? th.primary : kind === 'danger' ? C.danger : 'transparent';
  return (
    <Pressable
      testID={props.testID}
      accessibilityRole="button"
      onPress={props.disabled ? undefined : props.onPress}
      style={[s.btn, { backgroundColor: bg, borderColor: border, opacity: props.disabled ? 0.45 : 1, paddingVertical: props.small ? 6 : 11, paddingHorizontal: props.small ? 12 : 18 }, props.style]}
    >
      <Text style={{ color: fg, fontWeight: '700', fontSize: props.small ? 13 : 15 }}>{props.title}</Text>
    </Pressable>
  );
}

/** Botón plano de la cuadrícula de la pantalla Día (estilo Time Recording). */
export function GridBtn(props: { title: string; onPress: () => void; disabled?: boolean; testID?: string; wide?: boolean }) {
  return (
    <Pressable testID={props.testID} accessibilityRole="button" onPress={props.disabled ? undefined : props.onPress} style={({ pressed }) => [s.grid, props.wide && { width: '100%' }, pressed && { backgroundColor: '#dcdcdc' }]}>
      <Text style={[s.gridText, props.disabled && { color: '#b5b5b5' }]}>{props.title}</Text>
    </Pressable>
  );
}

export function Badge(props: { text: string; color?: string; bg?: string }) {
  return (
    <View style={{ backgroundColor: props.bg ?? '#e5e7eb', borderRadius: 99, paddingHorizontal: 9, paddingVertical: 2, alignSelf: 'flex-start' }}>
      <Text style={{ color: props.color ?? '#374151', fontSize: 12, fontWeight: '700' }}>{props.text}</Text>
    </View>
  );
}

export const STATUS: Record<string, { key: 'status.draft' | 'status.submitted' | 'status.approved' | 'status.returned'; color: string; bg: string }> = {
  draft: { key: 'status.draft', color: '#374151', bg: '#e5e7eb' },
  submitted: { key: 'status.submitted', color: '#7a5200', bg: '#fff4d6' },
  approved: { key: 'status.approved', color: C.ok, bg: '#e6f4ea' },
  returned: { key: 'status.returned', color: C.danger, bg: '#fdecea' },
};

/** Espacio al pie para que nada choque con la barra de navegación del teléfono (gestos o botones). */
export function useBottomPad(extra = 16): number {
  return useSafeAreaInsets().bottom + extra;
}

/** Pastilla para escoger (proyecto, servicio, filtros). */
export function Chip(props: { label: string; on: boolean; onPress: () => void; testID?: string; small?: boolean }) {
  const th = useTheme();
  return (
    <Pressable
      testID={props.testID}
      accessibilityRole="button"
      onPress={props.onPress}
      style={{ paddingHorizontal: props.small ? 10 : 14, paddingVertical: props.small ? 5 : 8, borderRadius: 99, borderWidth: 1, borderColor: props.on ? th.primary : '#c9ced6', backgroundColor: props.on ? th.primary : '#fff', marginRight: 8 }}
    >
      <Text style={{ color: props.on ? '#fff' : C.text, fontWeight: '700', fontSize: props.small ? 12 : 14 }} numberOfLines={1}>{props.label}</Text>
    </Pressable>
  );
}

export function Card(props: { children: React.ReactNode; style?: ViewStyle }) {
  return <View style={[s.card, props.style]}>{props.children}</View>;
}

export function Msg(props: { kind: 'ok' | 'err' | 'warn'; text: string }) {
  const m = { ok: ['#e6f4ea', C.ok], err: ['#fdecea', C.danger], warn: ['#fff4d6', '#e0a800'] }[props.kind];
  return (
    <View style={{ backgroundColor: m[0], borderLeftWidth: 4, borderLeftColor: m[1], padding: 10, borderRadius: 8, marginBottom: 10 }}>
      <Text style={{ color: C.text }}>{props.text}</Text>
    </View>
  );
}

export function Field(props: { label: string; value: string; onChangeText: (t: string) => void; keyboardType?: 'default' | 'numeric' | 'email-address' | 'decimal-pad'; secure?: boolean; placeholder?: string; multiline?: boolean; testID?: string; autoCapitalize?: 'none' | 'sentences' }) {
  return (
    <View style={{ marginBottom: 12 }}>
      <Text style={s.label}>{props.label}</Text>
      <TextInput
        testID={props.testID}
        style={[s.input, props.multiline && { height: 70, textAlignVertical: 'top' }]}
        value={props.value}
        onChangeText={props.onChangeText}
        keyboardType={props.keyboardType}
        secureTextEntry={props.secure}
        placeholder={props.placeholder}
        multiline={props.multiline}
        autoCapitalize={props.autoCapitalize ?? 'none'}
        autoCorrect={false}
      />
    </View>
  );
}

/** Propiedades del campo según el ojito (lógica pura, probada aparte). */
export function passwordFieldProps(visible: boolean, kind: 'current' | 'new' = 'current') {
  return {
    secureTextEntry: !visible,
    autoComplete: (kind === 'new' ? 'new-password' : 'current-password') as 'new-password' | 'current-password',
    textContentType: (kind === 'new' ? 'newPassword' : 'password') as 'newPassword' | 'password',
  };
}

/** Campo de contraseña con ojito para ver u ocultar lo que la persona escribió. UN solo componente para toda la app. */
export function PasswordInput(props: { label: string; value: string; onChangeText: (t: string) => void; testID?: string; kind?: 'current' | 'new'; error?: string; hint?: string }) {
  const { t } = useT();
  const [visible, setVisible] = React.useState(false);
  const f = passwordFieldProps(visible, props.kind);
  return (
    <View style={{ marginBottom: 12 }}>
      <Text style={s.label}>{props.label}</Text>
      <View style={{ justifyContent: 'center' }}>
        <TextInput
          testID={props.testID}
          style={[s.input, { paddingRight: 46 }, props.error ? { borderColor: C.danger } : null]}
          value={props.value}
          onChangeText={props.onChangeText}
          secureTextEntry={f.secureTextEntry}
          autoComplete={f.autoComplete}
          textContentType={f.textContentType}
          autoCapitalize="none"
          autoCorrect={false}
        />
        <Pressable
          testID={props.testID ? props.testID + '-eye' : undefined}
          accessibilityRole="button"
          accessibilityLabel={visible ? t('password.hide') : t('password.show')}
          accessibilityState={{ selected: visible }}
          hitSlop={8}
          onPress={() => setVisible((v) => !v)}
          style={{ position: 'absolute', right: 0, top: 0, bottom: 0, width: 44, alignItems: 'center', justifyContent: 'center' }}
        >
          <Text style={{ fontSize: 18, opacity: visible ? 1 : 0.6 }}>{visible ? '🙈' : '👁'}</Text>
        </Pressable>
      </View>
      {props.error ? <Text style={{ color: C.danger, fontSize: 12, marginTop: 3 }}>{props.error}</Text> : props.hint ? <Text style={{ color: C.muted, fontSize: 12, marginTop: 3 }}>{props.hint}</Text> : null}
    </View>
  );
}

/** Hora con reloj: en el teléfono abre el reloj del sistema; en la web, un campo de hora. */
export function TimeField(props: { label: string; value: string | null; onChange: (t: string) => void; testID?: string }) {
  const { t } = useT();
  const [open, setOpen] = React.useState(false);
  const v = isValidHM(props.value) ? props.value : null;
  if (Platform.OS === 'web') {
    return (
      <View style={{ flex: 1, marginBottom: 12 }}>
        <Text style={s.label}>{props.label}</Text>
        {React.createElement('input', {
          type: 'time', value: v ?? '', 'data-testid': props.testID,
          onChange: (e: any) => props.onChange(e.target.value),
          style: { height: 42, borderRadius: 8, border: '1px solid #c9ced6', padding: '0 10px', fontSize: 16 },
        })}
      </View>
    );
  }
  const date = new Date();
  if (v) date.setHours(Number(v.slice(0, 2)), Number(v.slice(3)), 0, 0);
  return (
    <View style={{ flex: 1, marginBottom: 12 }}>
      <Text style={s.label}>{props.label}</Text>
      <Pressable testID={props.testID} style={[s.input, { justifyContent: 'center' }]} onPress={() => setOpen(true)}>
        <Text style={{ fontSize: 16, color: v ? C.text : C.muted }}>{v ? to12h(v) : t('ui.pick_time')}</Text>
      </Pressable>
      {open && (
        <DateTimePicker
          value={date}
          mode="time"
          is24Hour={false}
          display="clock"
          onChange={(ev, d) => {
            setOpen(false);
            if (ev.type === 'set' && d) props.onChange(`${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`);
          }}
        />
      )}
    </View>
  );
}

export function Sheet(props: { visible: boolean; title: string; onClose: () => void; children: React.ReactNode }) {
  const { t } = useT();
  const bottom = useBottomPad(16);
  return (
    <Modal visible={props.visible} animationType="slide" transparent onRequestClose={props.onClose}>
      <View style={s.backdrop}>
        <View style={[s.sheet, { paddingBottom: bottom }]}>
          <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 8 }}>
            <Text style={{ flex: 1, fontSize: 18, fontWeight: '700' }}>{props.title}</Text>
            <Btn title={t('common.close')} kind="ghost" small onPress={props.onClose} />
          </View>
          <ScrollView keyboardShouldPersistTaps="handled">{props.children}</ScrollView>
        </View>
      </View>
    </Modal>
  );
}

export const s = StyleSheet.create({
  btn: { borderRadius: 8, borderWidth: 1, alignItems: 'center', justifyContent: 'center' } as ViewStyle,
  grid: { width: '50%', height: 46, borderWidth: StyleSheet.hairlineWidth, borderColor: C.line, backgroundColor: C.bg, alignItems: 'center', justifyContent: 'center' } as ViewStyle,
  gridText: { fontSize: 14, fontWeight: '700', letterSpacing: 0.3, textTransform: 'uppercase', color: '#111' } as TextStyle,
  card: { backgroundColor: C.card, borderRadius: 10, padding: 14, marginBottom: 12 } as ViewStyle,
  label: { fontSize: 12, color: C.muted, marginBottom: 3 } as TextStyle,
  input: { height: 42, borderWidth: 1, borderColor: '#c9ced6', borderRadius: 8, paddingHorizontal: 10, fontSize: 16, backgroundColor: '#fff', color: C.text } as TextStyle,
  backdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,0.4)', justifyContent: 'flex-end' } as ViewStyle,
  sheet: { backgroundColor: '#fff', borderTopLeftRadius: 16, borderTopRightRadius: 16, padding: 16, maxHeight: '80%' } as ViewStyle,
});

/**
 * Reloj para fijar una hora (entrada o salida), como en Time Recording: se abre ya puesto en la hora indicada
 * (normalmente la actual), se ajusta y se confirma.
 *  - Android: el reloj del sistema (con Aceptar / Cancelar).
 *  - iOS y web: una hoja con el selector y el botón de confirmar.
 */
export function ClockPicker(props: { visible: boolean; title: string; initial: string; confirmLabel?: string; onConfirm: (hm: string) => void; onCancel: () => void }) {
  const { t } = useT();
  const [val, setVal] = React.useState(props.initial);
  React.useEffect(() => {
    if (props.visible) setVal(isValidHM(props.initial) ? props.initial : nowHM());
  }, [props.visible, props.initial]);
  if (!props.visible) return null;
  const date = new Date();
  if (isValidHM(val)) date.setHours(Number(val.slice(0, 2)), Number(val.slice(3)), 0, 0);
  const hm = (d: Date) => `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;

  if (Platform.OS === 'android') {
    return (
      <DateTimePicker
        value={date}
        mode="time"
        is24Hour={false}
        display="clock"
        onChange={(ev, d) => {
          if (ev.type === 'set' && d) props.onConfirm(hm(d));
          else props.onCancel();
        }}
      />
    );
  }
  return (
    <Sheet visible title={props.title} onClose={props.onCancel}>
      {Platform.OS === 'web' ? (
        <View style={{ marginBottom: 12 }}>
          {React.createElement('input', {
            type: 'time', value: isValidHM(val) ? val : '', 'data-testid': 'clock-input',
            onChange: (e: any) => setVal(e.target.value),
            style: { height: 48, borderRadius: 8, border: '1px solid #c9ced6', padding: '0 10px', fontSize: 20 },
          })}
        </View>
      ) : (
        <DateTimePicker value={date} mode="time" display="spinner" onChange={(_ev, d) => d && setVal(hm(d))} />
      )}
      <Btn testID="clock-confirm" title={props.confirmLabel ?? t('common.confirm')} kind="pri" disabled={!isValidHM(val)} onPress={() => props.onConfirm(val)} />
    </Sheet>
  );
}
