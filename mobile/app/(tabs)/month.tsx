import React, { useMemo } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { dayTotals } from '../../src/lib/report';
import { addDays, fmtDate, fmtHours, monthGrid, monthLabel, parseDate, today } from '../../src/lib/time';
import { useT } from '../../src/i18n';
import { useEntries } from '../../src/store/entries';
import { useSettings } from '../../src/store/settings';
import { C, useBottomPad, useTheme } from '../../src/ui';


export default function Month() {
  const router = useRouter();
  const th = useTheme();
  const { t } = useT();
  const bottom = useBottomPad(12);
  const HEAD = t('month.head').split(',');
  const decimal = useSettings((s) => s.decimal);
  const { entries, selectedDate, setDate } = useEntries();
  const grid = useMemo(() => monthGrid(selectedDate), [selectedDate]);
  const totals = useMemo(() => dayTotals(entries), [entries]);
  const monthTotal = grid.flat().reduce((a, d) => a + (d ? totals.get(d) ?? 0 : 0), 0);

  const shift = (n: number) => {
    const d = parseDate(selectedDate);
    setDate(fmtDate(new Date(d.getFullYear(), d.getMonth() + n, 1)));
  };

  return (
    <ScrollView style={{ flex: 1, backgroundColor: C.bg }} contentContainerStyle={{ padding: 12, paddingBottom: bottom }}>
      <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 8 }}>
        <Pressable testID="mprev" onPress={() => shift(-1)} style={{ padding: 8 }}><Text style={{ fontSize: 22, color: th.primary }}>‹</Text></Pressable>
        <Text testID="month-label" style={{ flex: 1, textAlign: 'center', fontWeight: '800', fontSize: 17, textTransform: 'capitalize' }}>{monthLabel(selectedDate)}</Text>
        <Pressable testID="mnext" onPress={() => shift(1)} style={{ padding: 8 }}><Text style={{ fontSize: 22, color: th.primary }}>›</Text></Pressable>
      </View>
      <View style={{ flexDirection: 'row' }}>
        {HEAD.map((h, i) => <Text key={i} style={{ flex: 1, textAlign: 'center', fontWeight: '700', color: C.muted }}>{h}</Text>)}
      </View>
      {grid.map((row, i) => (
        <View key={i} style={{ flexDirection: 'row' }}>
          {row.map((d, j) => {
            const h = d ? totals.get(d) : undefined;
            return (
              <Pressable
                key={j}
                testID={d ? `cell-${d}` : undefined}
                disabled={!d}
                onPress={() => { if (d) { setDate(d); router.navigate('/'); } }}
                style={{ flex: 1, height: 58, margin: 1, borderRadius: 6, padding: 4, backgroundColor: !d ? 'transparent' : d === today() ? '#dbeafe' : '#fff', borderWidth: d === selectedDate ? 2 : 0, borderColor: th.accent }}
              >
                {d ? <Text style={{ fontSize: 12, color: C.muted }}>{Number(d.slice(8))}</Text> : null}
                {h ? <Text style={{ fontWeight: '800', fontSize: 14, color: th.primary }}>{fmtHours(h, decimal)}</Text> : null}
              </Pressable>
            );
          })}
        </View>
      ))}
      <Text testID="month-total" style={{ marginTop: 12, fontWeight: '800', fontSize: 16 }}>{t('month.total')}: {fmtHours(monthTotal, decimal)} h</Text>
    </ScrollView>
  );
}
