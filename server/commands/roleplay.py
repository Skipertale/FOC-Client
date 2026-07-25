import random

import asyncio
import arrow
import time
import datetime
import pytimeparse
import shlex

from server import database
from server.constants import TargetType
from server.exceptions import ClientError, ServerError, ArgumentError

from . import mod_only
from .. import commands

__all__ = [
    "ooc_cmd_roll",
    "ooc_cmd_rollp",
    "ooc_cmd_r10",
    "ooc_cmd_r10b",
    "ooc_cmd_rp10",
    "ooc_cmd_h10",
    "ooc_cmd_re10",
    "ooc_cmd_notecard",
    "ooc_cmd_notecard_clear",
    "ooc_cmd_notecard_reveal",
    "ooc_cmd_notecard_check",
    "ooc_cmd_vote",
    "ooc_cmd_vote_clear",
    "ooc_cmd_vote_reveal",
    "ooc_cmd_vote_check",
    "ooc_cmd_rolla_reload",
    "ooc_cmd_rolla_set",
    "ooc_cmd_rolla",
    "ooc_cmd_coinflip",
    "ooc_cmd_8ball",
    "ooc_cmd_rps",
    "ooc_cmd_rps_rules",
    "ooc_cmd_timer",
    "ooc_cmd_demo",
    "ooc_cmd_trigger",
    "ooc_cmd_format_timer",
    "ooc_cmd_timer_interval",
    "ooc_cmd_in10",
    "ooc_cmd_in10d",
]


# ─── Initiative (VtM) ───

_initiatives = {}  # {area_id: {"total": int, "rolls": [(name, modifier, roll, total), ...]}}


def ooc_cmd_in10(client, arg):
    """
    Start an initiative check. Usage: /in10 <количество участников>
    После этого каждый участник кидает /in10d <имя> <модификатор>.
    Когда все кинули — порядок инициативы публикуется автоматически.
    """
    try:
        total = int(arg.strip())
    except (ValueError, AttributeError):
        client.send_ooc("Usage: /in10 <количество участников>")
        return
    if total < 1:
        client.send_ooc("Количество участников должно быть больше 0.")
        return
    if total > 50:
        client.send_ooc("Слишком много участников (максимум 50).")
        return
    area_id = client.area.id
    _initiatives[area_id] = {"total": total, "rolls": []}
    client.area.broadcast_ooc(
        f"[Initiative] {client.showname} начал проверку инициативы на {total} участников. "
        f"Используйте /in10d <имя> <модификатор> чтобы кинуть d10+модификатор."
    )


def ooc_cmd_in10d(client, arg):
    """
    Roll initiative (d10 + modifier). Usage: /in10d <имя> <модификатор>
    Example: /in10d Бурмалда 6
    """
    area_id = client.area.id
    state = _initiatives.get(area_id)
    if state is None:
        client.send_ooc("Инициатива не запущена. Сначала используйте /in10 <количество участников>.")
        return

    parts = arg.strip().rsplit(None, 1)
    if len(parts) != 2:
        client.send_ooc("Usage: /in10d <имя> <модификатор>")
        return

    name = parts[0]
    try:
        modifier = int(parts[1])
    except ValueError:
        client.send_ooc("Модификатор должен быть числом.")
        return

    if not name:
        client.send_ooc("Имя не может быть пустым.")
        return

    if any(r[0].lower() == name.lower() for r in state["rolls"]):
        client.send_ooc(f"Персонаж '{name}' уже кинул инициативу.")
        return

    roll = random.randint(1, 10)
    total = roll + modifier

    state["rolls"].append((name, modifier, roll, total))
    client.area.broadcast_ooc(f"[Initiative] {name} (мод. {modifier}) кинул d10: {roll} + {modifier} = {total}")

    remaining = state["total"] - len(state["rolls"])
    if remaining > 0:
        client.area.broadcast_ooc(f"[Initiative] Ожидается ещё {remaining} участников.")
    else:
        # Все кинули — публикуем порядок
        sorted_rolls = sorted(state["rolls"], key=lambda r: r[3], reverse=True)
        lines = " > ".join(f"{r[0]} ({r[3]})" for r in sorted_rolls)
        client.area.broadcast_ooc(f"[Initiative] === Порядок инициативы ===\n{lines}")
        del _initiatives[area_id]


def rtd(arg):
    DICE_MAX = 11037
    NUMDICE_MAX = 20
    MODIFIER_LENGTH_MAX = 12  # Change to a higher at your own risk
    ACCEPTABLE_IN_MODIFIER = "1234567890+-*/().r"
    MAXDIVZERO_ATTEMPTS = 10
    MAXACCEPTABLETERM = DICE_MAX * 10  # Change to a higher number at your own risk

    special_calculation = False
    args = arg.split(" ")
    arg_length = len(args)

    if arg != "":
        if arg_length == 2:
            dice_type, modifiers = args
            if len(modifiers) > MODIFIER_LENGTH_MAX:
                raise ArgumentError(
                    "The given modifier is too long to compute. Please try a shorter one"
                )
        elif arg_length == 1:
            dice_type, modifiers = arg, ""
        else:
            raise ArgumentError(
                "This command takes one or two arguments. Use /roll [<num of rolls>]d[<max>] [modifiers]"
            )

        dice_type = dice_type.split("d")
        if len(dice_type) == 1:
            dice_type.insert(0, 1)
        if dice_type[0] == "":
            dice_type[0] = "1"

        try:
            num_dice, chosen_max = int(dice_type[0]), int(dice_type[1])
        except ValueError:
            raise ArgumentError(
                "Expected integer value for number of rolls and max value of dice"
            )

        if not 1 <= num_dice <= NUMDICE_MAX:
            raise ArgumentError(
                "Number of rolls must be between 1 and {}".format(NUMDICE_MAX)
            )
        if not 1 <= chosen_max <= DICE_MAX:
            raise ArgumentError(
                "Dice value must be between 1 and {}".format(DICE_MAX))

        for char in modifiers:
            if char not in ACCEPTABLE_IN_MODIFIER:
                raise ArgumentError(
                    "Expected numbers and standard mathematical operations in modifier"
                )
            if char == "r":
                special_calculation = True
        if (
            "**" in modifiers
        ):  # Exponentiation manually disabled, it can be pretty dangerous
            raise ArgumentError(
                "Expected numbers and standard mathematical operations in modifier"
            )
    else:
        num_dice, chosen_max, modifiers = 1, 6, ""  # Default

    roll = ""
    Sum = 0

    for i in range(num_dice):
        divzero_attempts = 0
        while True:
            raw_roll = str(random.randint(1, chosen_max))
            if modifiers == "":
                aux_modifier = ""
                mid_roll = int(raw_roll)
            else:
                if special_calculation:
                    aux_modifier = modifiers.replace("r", raw_roll) + "="
                elif modifiers[0].isdigit():
                    aux_modifier = raw_roll + "+" + modifiers + "="
                else:
                    aux_modifier = raw_roll + modifiers + "="

                # Prevent any terms from reaching past MAXACCEPTABLETERM in order to prevent server lag due to potentially frivolous dice rolls
                aux = aux_modifier[:-1]
                for i in "+-*/()":
                    aux = aux.replace(i, "!")
                aux = aux.split("!")
                for i in aux:
                    try:
                        if i != "" and round(float(i)) > MAXACCEPTABLETERM:
                            raise ArgumentError(
                                "Given mathematical formula takes numbers past the server's computation limit"
                            )
                    except ValueError:
                        raise ArgumentError(
                            "Given mathematical formula has a syntax error and cannot be computed"
                        )

                try:
                    mid_roll = round(
                        eval(aux_modifier[:-1])
                    )  # By this point it should be 'safe' to run eval
                except SyntaxError:
                    raise ArgumentError(
                        "Given mathematical formula has a syntax error and cannot be computed"
                    )
                except TypeError:  # Deals with inputs like 3(r-1)
                    raise ArgumentError(
                        "Given mathematical formula has a syntax error and cannot be computed"
                    )
                except ZeroDivisionError:
                    divzero_attempts += 1
                    if divzero_attempts == MAXDIVZERO_ATTEMPTS:
                        raise ArgumentError(
                            "Given mathematical formula produces divisions by zero too often and cannot be computed"
                        )
                    continue
            break

        final_roll = mid_roll  # min(chosen_max,max(1,mid_roll))
        Sum += final_roll
        if final_roll != mid_roll:
            final_roll = "|" + str(
                final_roll
            )  # This visually indicates the roll was capped off due to exceeding the acceptable roll range
        else:
            final_roll = str(final_roll)
        if modifiers != "":
            roll += str(raw_roll + ":")
        roll += str(aux_modifier + final_roll) + ", "
    roll = roll[:-2]
    if num_dice > 1:
        roll = "(" + roll + ")"

    return roll, num_dice, chosen_max, modifiers, Sum


def ooc_cmd_roll(client, arg):
    """
    Roll a die. The result is shown publicly.
    Example: /roll 2d6 +5 would roll two 6-sided die and add 5 to every result.
    Rolls a 1d6 if blank
    X is the number of dice, Y is the maximum value on the die.
    Usage: /rollp [value/XdY] ["+5"/"-5"/"*5"/"/5"]
    """
    roll, num_dice, chosen_max, _modifiers, Sum = rtd(arg)

    client.area.broadcast_ooc(
        f"{client.showname} rolled {roll} out of {chosen_max}."
        + (f"\nThe total sum is {Sum}." if num_dice > 1 else "")
    )
    database.log_area(
        "roll", client, client.area, message=f"{roll} out of {chosen_max}"
    )


def ooc_cmd_rollp(client, arg):
    """
    Roll a die privately. Same as /roll but the result is only shown to you and the CMs.
    Example: /roll 2d6 +5 would roll two 6-sided die and add 5 to every result.
    Rolls a 1d6 if blank
    X is the number of dice, Y is the maximum value on the die.
    Usage: /rollp [value/XdY] ["+5"/"-5"/"*5"/"/5"]
    """
    roll, num_dice, chosen_max, _modifiers, Sum = rtd(arg)

    client.send_ooc(
        f"[Hidden] You rolled {roll} out of {chosen_max}."
        + (f"\nThe total sum is {Sum}." if num_dice > 1 else "")
    )
    for c in client.area.owners:
        c.send_ooc(
            f"[{client.area.id}]{client.showname} secretly rolled {roll} out of {chosen_max}."
        )

    database.log_area(
        "rollp", client, client.area, message=f"{roll} out of {chosen_max}"
    )

def ooc_cmd_r10(client, arg):
    """
    Roll a specified number of d10 and calculate the number of successes with exploding dice.
    Example: /r10 4 6 1 means rolling 4 dice with difficulty 6 and exploding 10s.
    If difficulty and explosion level are not provided, default to difficulty 6 and explosion level 0.
    """
    MAX_DICE = 45  # Ограничение на количество кубиков

    try:
        # Разделяем аргументы, если они есть
        args = list(map(int, arg.split()))
        if len(args) == 1:
            num_dice = args[0]
            difficulty = 6  # По умолчанию сложность 6
            explosion_level = 0  # По умолчанию уровень взрыва 0 (ничего не взрывается)
        elif len(args) == 2:
            num_dice, difficulty = args
            explosion_level = 0  # По умолчанию уровень взрыва 0 (ничего не взрывается)
        elif len(args) == 3:
            num_dice, difficulty, explosion_level = args
        else:
            client.send_ooc("Invalid input. Usage: /r10 [dicepool] [difficulty] [explosion level].")
            return
    except ValueError:
        client.send_ooc("Invalid input. Usage: /r10 [dicepool] [difficulty] [explosion level].")
        return

    # Проверяем количество кубиков
    if num_dice > MAX_DICE:
        client.send_ooc(f"Error: You cannot roll more than {MAX_DICE} dice.")
        return

    rolls = [random.randint(1, 10) for _ in range(num_dice)]
    successes = sum(1 for roll in rolls if roll >= difficulty)
    failures = sum(1 for roll in rolls if roll == 1)

    result = []  # Для хранения результатов бросков с взрывами

    # Обработка взрыва кубиков
    for roll in rolls:
        exploded_rolls = []  # Для хранения взорванных бросков
        success = 0

        # Условие взрыва на основе уровня взрыва
        while (explosion_level == 1 and roll == 10) or \
              (explosion_level == 2 and roll == 10) or \
              (explosion_level == 3 and roll >= 9) or \
              (explosion_level == 4 and roll >= 8):
            new_roll = random.randint(1, 10)
            exploded_rolls.append(new_roll)

            # Учитываем успех, если новый бросок соответствует сложности
            if new_roll >= difficulty:
                success += 1

            # Если уровень взрыва 1, взрываем только один раз
            if explosion_level == 1 and new_roll == 10:
                break

            # Для уровней 1 и 2 только 10 может взорваться. Прерываем цикл, если выпало что-то другое
            if explosion_level == 1 or explosion_level == 2:
                if new_roll != 10:
                    break
            # Для уровней 3 и 4 прерываем, если результат не соответствует критериям взрыва
            elif explosion_level == 3 and new_roll < 9:
                break
            elif explosion_level == 4 and new_roll < 8:
                break

            roll = new_roll

        # Добавляем успехи от взорванных бросков
        successes += success

        # Форматируем результат, показывая взорванные броски, если они есть
        if exploded_rolls:
            result.append(f"{roll}({','.join(map(str, exploded_rolls))})")
        else:
            result.append(str(roll))

    total_successes = max(0, successes - failures)  # Успехи не могут быть меньше нуля

    # Проверяем на драматический провал
    if successes == 0 and failures > 0:
        client.area.broadcast_ooc(f"{client.showname} rolled: [{', '.join(result)}]. Dramatic failure! (Difficulty: {difficulty})")
    else:
        client.area.broadcast_ooc(f"{client.showname} rolled: [{', '.join(result)}]. Total successes: {total_successes} (Difficulty: {difficulty})")

    # Логирование результата
    database.log_area("r10", client, client.area, message=f"{result}. Total successes: {total_successes} (Difficulty: {difficulty})")

def ooc_cmd_r10b(client, arg):
    """
    Roll dice for damage with exploding dice and a base damage calculation.
    Example: /r10b [dicepool] [base damage] [difficulty] [explosion level] [ones in damage].
    """
    try:
        args = arg.split()
        num_dice = int(args[0])  # Number of dice to roll
        base_damage = int(args[1])  # Base damage
        difficulty = int(args[2]) if len(args) > 2 else 6  # Difficulty, default is 6
        explosion_level = int(args[3]) if len(args) > 3 else 0  # Explosion level, default is no explosion
        ones_in_damage = int(args[4]) if len(args) > 4 else 0  # Whether ones reduce successes in damage (0 or 1)
    except (ValueError, IndexError):
        client.send_ooc("Invalid input. Usage: /r10b [dicepool] [base damage] [difficulty] [explosion level] [ones in damage].")
        return

    MAX_DICE = 45  # Ограничение на количество кубиков
    if num_dice > MAX_DICE:
        client.send_ooc(f"Error: You cannot roll more than {MAX_DICE} dice.")
        return

    # Roll dice for attack
    rolls = [random.randint(1, 10) for _ in range(num_dice)]
    successes = sum(1 for roll in rolls if roll >= difficulty)
    failures = sum(1 for roll in rolls if roll == 1)

    result = []
    
    for roll in rolls:
        exploded_rolls = []  # Store exploded rolls
        success = 0

        # Exploding dice logic
        while (explosion_level == 1 and roll == 10) or \
              (explosion_level == 2 and roll == 10) or \
              (explosion_level == 3 and roll >= 9) or \
              (explosion_level == 4 and roll >= 8):
            new_roll = random.randint(1, 10)
            exploded_rolls.append(new_roll)

            if new_roll >= difficulty:
                success += 1

            # Explosion logic based on level 2 (10 explodes twice)
            if explosion_level == 2 and new_roll == 10:
                double_explosion = [random.randint(1, 10) for _ in range(2)]
                exploded_rolls.extend(double_explosion)
                success += sum(1 for r in double_explosion if r >= difficulty)
                break  # No further explosions after double

            roll = new_roll

        successes += success

        if exploded_rolls:
            result.append(f"{roll}({','.join(map(str, exploded_rolls))})")
        else:
            result.append(str(roll))

    total_successes = max(0, successes - failures)

    # Сообщение о броске на атаку
    client.area.broadcast_ooc(f"{client.showname} rolled for attack: [{', '.join(result)}]. Total successes: {total_successes} (Difficulty: {difficulty}).")

    # Урон по формуле
    if total_successes > 0:
        num_damage_dice = base_damage + (total_successes - 1)
        damage_rolls = [random.randint(1, 10) for _ in range(num_damage_dice)]
        damage_successes = sum(1 for roll in damage_rolls if roll >= difficulty)
        
        if ones_in_damage == 1:
            damage_failures = sum(1 for roll in damage_rolls if roll == 1)
            damage_successes = max(0, damage_successes - damage_failures)
        
        # Сообщение о броске на урон
        client.area.broadcast_ooc(f"{client.showname} rolled for damage: [{', '.join(map(str, damage_rolls))}]. Damage calculation: Base damage = {base_damage}, total damage = {damage_successes}.")
    else:
        client.area.broadcast_ooc(f"{client.showname} did not roll for damage. Damage calculation: Base damage = {base_damage}, total damage = 0.")

def ooc_cmd_rp10(client, arg):
    """
    Roll a specified number of d10 privately and calculate the number of successes with exploding dice.
    Example: /rp10 4 6 1 means rolling 4 dice with difficulty 6 and exploding 10s, privately.
    If difficulty and explosion level are not provided, default to difficulty 6 and explosion level 0.
    """
    MAX_DICE = 45  # Ограничение на количество кубиков

    try:
        # Разделяем аргументы
        args = list(map(int, arg.split()))
        if len(args) == 1:
            num_dice = args[0]
            difficulty = 6  # По умолчанию сложность 6
            explosion_level = 0  # По умолчанию уровень взрыва 0 (ничего не взрывается)
        elif len(args) == 2:
            num_dice, difficulty = args
            explosion_level = 0  # По умолчанию уровень взрыва 0
        elif len(args) == 3:
            num_dice, difficulty, explosion_level = args
        else:
            client.send_ooc("Invalid input. Usage: /rp10 [dicepool] [difficulty] [explosion level].")
            return
    except ValueError:
        client.send_ooc("Invalid input. Usage: /rp10 [dicepool] [difficulty] [explosion level].")
        return

    # Проверяем количество кубиков
    if num_dice > MAX_DICE:
        client.send_ooc(f"Error: You cannot roll more than {MAX_DICE} dice.")
        return

    rolls = [random.randint(1, 10) for _ in range(num_dice)]
    successes = sum(1 for roll in rolls if roll >= difficulty)
    failures = sum(1 for roll in rolls if roll == 1)

    result = []  # Для хранения результатов бросков с взрывами

    # Обработка взрыва кубиков
    for roll in rolls:
        exploded_rolls = []  # Для хранения взорванных бросков
        success = 0

        # Условие взрыва на основе уровня взрыва
        while (explosion_level == 1 and roll == 10) or \
              (explosion_level == 2 and roll == 10) or \
              (explosion_level == 3 and roll >= 9) or \
              (explosion_level == 4 and roll >= 8):
            new_roll = random.randint(1, 10)
            exploded_rolls.append(new_roll)

            # Учитываем успех, если новый бросок соответствует сложности
            if new_roll >= difficulty:
                success += 1

            # Если уровень взрыва 1, взрываем только один раз
            if explosion_level == 1 and new_roll == 10:
                break

            # Для уровней 1 и 2 только 10 может взорваться. Прерываем цикл, если выпало что-то другое
            if explosion_level == 1 or explosion_level == 2:
                if new_roll != 10:
                    break
            # Для уровней 3 и 4 прерываем, если результат не соответствует критериям взрыва
            elif explosion_level == 3 and new_roll < 9:
                break
            elif explosion_level == 4 and new_roll < 8:
                break

            roll = new_roll

        # Добавляем успехи от взорванных бросков
        successes += success

        # Форматируем результат, показывая взорванные броски, если они есть
        if exploded_rolls:
            result.append(f"{roll}({','.join(map(str, exploded_rolls))})")
        else:
            result.append(str(roll))

    total_successes = max(0, successes - failures)  # Успехи не могут быть меньше нуля

    # Проверяем на драматический провал
    if successes == 0 and failures > 0:
        client.send_ooc(f"[Hidden] You rolled: [{', '.join(result)}]. Dramatic failure! (Difficulty: {difficulty})")
    else:
        client.send_ooc(f"[Hidden] You rolled: [{', '.join(result)}]. Total successes: {total_successes} (Difficulty: {difficulty})")

    # Отправляем результат владельцам области (CMs)
    for c in client.area.owners:
        c.send_ooc(f"[{client.area.id}][Hidden] {client.showname} secretly rolled: [{', '.join(result)}]. Total successes: {total_successes} (Difficulty: {difficulty})")

    # Логируем результат
    database.log_area("rp10", client, client.area, message=f"{result}. Total successes: {total_successes} (Difficulty: {difficulty})")

def ooc_cmd_h10(client, arg):
    """
    Roll a specified number of d10, but only the CMs (area owners) can see the result.
    Example: /h10 4 6 1 means rolling 4 dice with difficulty 6 and exploding 10s, only CMs see the result.
    If difficulty and explosion level are not provided, default to difficulty 6 and explosion level 0.
    """
    MAX_DICE = 45  # Ограничение на количество кубиков

    try:
        # Разделяем аргументы
        args = list(map(int, arg.split()))
        if len(args) == 1:
            num_dice = args[0]
            difficulty = 6  # По умолчанию сложность 6
            explosion_level = 0  # По умолчанию уровень взрыва 0 (ничего не взрывается)
        elif len(args) == 2:
            num_dice, difficulty = args
            explosion_level = 0  # По умолчанию уровень взрыва 0
        elif len(args) == 3:
            num_dice, difficulty, explosion_level = args
        else:
            client.send_ooc("Invalid input. Usage: /h10 [dicepool] [difficulty] [explosion level].")
            return
    except ValueError:
        client.send_ooc("Invalid input. Usage: /h10 [dicepool] [difficulty] [explosion level].")
        return

    # Проверяем количество кубиков
    if num_dice > MAX_DICE:
        client.send_ooc(f"Error: You cannot roll more than {MAX_DICE} dice.")
        return

    rolls = [random.randint(1, 10) for _ in range(num_dice)]
    successes = sum(1 for roll in rolls if roll >= difficulty)
    failures = sum(1 for roll in rolls if roll == 1)

    result = []  # Для хранения результатов бросков с взрывами

    # Обработка взрыва кубиков
    for roll in rolls:
        exploded_rolls = []  # Для хранения взорванных бросков
        success = 0

        # Условие взрыва на основе уровня взрыва
        while (explosion_level == 1 and roll == 10) or \
              (explosion_level == 2 and roll == 10) or \
              (explosion_level == 3 and roll >= 9) or \
              (explosion_level == 4 and roll >= 8):
            new_roll = random.randint(1, 10)
            exploded_rolls.append(new_roll)

            # Учитываем успех, если новый бросок соответствует сложности
            if new_roll >= difficulty:
                success += 1

            # Если уровень взрыва 1, взрываем только один раз
            if explosion_level == 1 and new_roll == 10:
                break

            # Для уровней 1 и 2 только 10 может взорваться. Прерываем цикл, если выпало что-то другое
            if explosion_level == 1 or explosion_level == 2:
                if new_roll != 10:
                    break
            # Для уровней 3 и 4 прерываем, если результат не соответствует критериям взрыва
            elif explosion_level == 3 and new_roll < 9:
                break
            elif explosion_level == 4 and new_roll < 8:
                break

            roll = new_roll

        # Добавляем успехи от взорванных бросков
        successes += success

        # Форматируем результат, показывая взорванные броски, если они есть
        if exploded_rolls:
            result.append(f"{roll}({','.join(map(str, exploded_rolls))})")
        else:
            result.append(str(roll))

    total_successes = max(0, successes - failures)  # Успехи не могут быть меньше нуля

    # Игроку выдаём только сообщение, что он бросил кубики
    client.send_ooc(f"You rolled {num_dice} d10. The result is hidden from you.")

    # Владельцам области отправляем детализированный отчёт
    for c in client.area.owners:
        c.send_ooc(f"[{client.area.id}][Hidden] {client.showname} rolled: [{', '.join(result)}]. Total successes: {total_successes} (Difficulty: {difficulty})")

    # Логируем результат
    database.log_area("hidden_r10", client, client.area, message=f"{result}. Total successes: {total_successes} (Difficulty: {difficulty})")

# Храним прогресс расширенного действия для каждого игрока
extended_successes = {}

def ooc_cmd_re10(client, arg):
    """
    Roll a specified number of d10 for an extended action, where a set number of successes is needed.
    Example: /re10 4 6 5 1 2 means rolling 4 dice with difficulty 6, requiring 5 total successes, 
    failing immediately if a roll fails, with explosion level 2.
    """
    global extended_successes

    MAX_DICE = 45  # Ограничение на количество кубиков
    
    try:
        num_dice, difficulty, required_successes, fail_on_miss, explosion_level = map(int, arg.split())
    except ValueError:
        client.send_ooc("Invalid input. Usage: /re10 [dicepool] [difficulty] [required successes] [fail on miss 0/1] [explosion level 0-4].")
        return

    # Проверяем количество кубиков
    if num_dice > MAX_DICE:
        client.send_ooc(f"Error: You cannot roll more than {MAX_DICE} dice.")
        return
    
    # Инициализируем прогресс, если это первый бросок
    if client.showname not in extended_successes:
        extended_successes[client.showname] = 0

    rolls = []
    successes = 0
    failures = 0

    # Выполняем бросок кубиков
    for _ in range(num_dice):
        roll = random.randint(1, 10)
        rolls.append(roll)
        if roll >= difficulty:
            successes += 1
        if roll == 1:
            failures += 1

    # Обработка взрывов
    exploded_rolls = []
    for roll in rolls:
        current_roll = roll
        roll_chain = [str(roll)]  # Для хранения цепочки взрывов
        while (explosion_level >= 1 and current_roll == 10) or \
              (explosion_level >= 3 and current_roll >= 9) or \
              (explosion_level == 4 and current_roll >= 8):
            new_roll = random.randint(1, 10)
            roll_chain.append(str(new_roll))
            if new_roll >= difficulty:
                successes += 1
            current_roll = new_roll

            # Условия для повторного взрыва на уровнях 2, 3 и 4
            if explosion_level == 2 and current_roll == 10:
                continue  # Повторяем взрыв для значения 10 (двойной взрыв)
            elif explosion_level == 3 and current_roll >= 9:
                continue  # Повторяем взрыв для значений 9 и 10
            elif explosion_level == 4 and current_roll >= 8:
                continue  # Повторяем взрыв для значений 8, 9 и 10
            else:
                break  # Останавливаем цепочку взрывов

        # Форматирование цепочки бросков с взрывами
        exploded_rolls.append(f"{roll_chain[0]}({','.join(roll_chain[1:])})" if len(roll_chain) > 1 else roll_chain[0])

    rolls = exploded_rolls

    # Вычитаем количество единиц из успехов
    net_successes = max(0, successes - failures)
    total_successes = extended_successes[client.showname] + net_successes

    # Обновляем прогресс по количеству успехов
    extended_successes[client.showname] = total_successes

    # Проверяем, драматический ли это провал
    if successes == 0 and failures > 0:
        client.area.broadcast_ooc(f"{client.showname} rolled: [{', '.join(rolls)}]. Dramatic failure! (Difficulty: {difficulty}).")
        client.area.broadcast_ooc(f"Extended action completely failed!")
        extended_successes[client.showname] = 0  # Обнуляем прогресс из-за драматического провала
        return

    # Если количество успехов достигло или превысило требуемое
    if total_successes >= required_successes:
        client.area.broadcast_ooc(f"{client.showname} rolled: [{', '.join(rolls)}]. Total successes: {net_successes} ({total_successes}) (Difficulty: {difficulty})")
        client.area.broadcast_ooc(f"Extended action completed successfully with {total_successes} successes!")
        extended_successes[client.showname] = 0  # Сбрасываем прогресс после завершения
    else:
        client.area.broadcast_ooc(f"{client.showname} rolled: [{', '.join(rolls)}]. Total successes: {net_successes} ({total_successes}) (Difficulty: {difficulty})")
        
        # Если установлен параметр fail_on_miss == 1 и успехов не было
        if fail_on_miss == 1 and successes == 0:
            client.area.broadcast_ooc(f"{client.showname} extended action has failed due to a missed roll.")
            extended_successes[client.showname] = 0  # Обнуляем прогресс

    # Логирование действия
    database.log_area("r10e", client, client.area, message=f"Roll: {', '.join(rolls)}, Total successes: {total_successes} (Difficulty: {difficulty})")


def ooc_cmd_notecard(client, arg):
    """
    Write a notecard that can only be revealed by a CM.
    Usage: /notecard <message>
    """
    if len(arg) == 0:
        if client.char_name in client.area.cards:
            client.send_ooc(
                f"Your current notecard is {client.area.cards[client.char_name]}. Usage: /notecard <message>"
            )
        else:
            client.send_ooc("No notecard found. Usage: /notecard <message>")
        return
    client.area.cards[client.char_name] = arg
    client.area.broadcast_ooc(f"[{client.id}] {client.showname} wrote a note card.")
    database.log_area("notecard", client, client.area)


@mod_only(area_owners=True)
def ooc_cmd_notecard_clear(client, arg):
    """
    Clear all notecards as a CM.
    Usage: /notecard_clear
    """
    client.area.cards.clear()
    client.area.broadcast_ooc(
        f"[{client.id}] {client.showname} has cleared all the note cards in this area."
    )
    database.log_area("notecard_clear", client, client.area)


@mod_only(area_owners=True)
def ooc_cmd_notecard_reveal(client, arg):
    """
    Reveal all notecards and their owners.
    Usage: /notecard_reveal
    """
    if len(client.area.cards) == 0:
        raise ClientError("There are no cards to reveal in this area.")
    msg = "Note cards have been revealed:"
    for card_owner, card_msg in client.area.cards.items():
        msg += f"\n{card_owner}: {card_msg}"
    client.area.broadcast_ooc(msg)
    client.send_ooc("Use /notecard_clear for clearing.")
    database.log_area("notecard_reveal", client, client.area)


@mod_only(area_owners=True)
def ooc_cmd_notecard_check(client, arg):
    """
    Check all notecards and their owners privately with a message telling others you've done so.
    Usage: /notecard_check
    """
    if len(client.area.cards) == 0:
        raise ClientError("There are no cards to check in this area.")
    client.area.broadcast_ooc(
        f"[{client.id}] {client.showname} has checked the notecards in this area."
    )
    msg = "Note cards in this area:"
    for card_owner, card_msg in client.area.cards.items():
        msg += f"\n{card_owner}: {card_msg}"
    client.send_ooc(msg)
    client.send_ooc(
        "Use /notecard_clear for clearing, or /notecard_reveal to reveal the results publicly."
    )
    database.log_area("notecard_check", client, client.area)


def ooc_cmd_vote(client, arg):
    """
    Cast a vote for a particular user that can only be revealed by a CM.
    Usage: /vote <id>
    """
    args = arg.split()
    if len(args) == 0:
        raise ArgumentError("Please provide a client ID. Usage: /vote <id>.")
    if client.char_name in [y for x in client.area.votes.values() for y in x]:
        raise ArgumentError(
            "You already cast your vote! Wait on the CM to /vote_clear."
        )
    target = client.server.client_manager.get_targets(
        client, TargetType.ID, int(args[0]), False
    )[0]
    client.area.votes.setdefault(target.char_name, []).append(client.char_name)
    client.area.broadcast_ooc(f"[{client.id}] {client.showname} cast a vote.")
    database.log_area("vote", client, client.area)


@mod_only(area_owners=True)
def ooc_cmd_vote_clear(client, arg):
    """
    Clear all votes as a CM.
    Include [char_folder] (case-sensitive) to only clear a specific voter.
    Usage: /vote_clear [char_folder]
    """
    if arg != "":
        for value in client.area.votes.values():
            if arg in value:
                value.remove(arg)
                client.area.broadcast_ooc(
                    f"[{client.id}] {client.showname} has cleared {arg}'s vote."
                )
                return
        raise ClientError(
            f"No vote was cast by {arg}! (This is case-sensitive - are you sure you spelt the voter character folder right?)"
        )
    client.area.votes.clear()
    client.area.broadcast_ooc(
        f"[{client.id}] {client.showname} has cleared all the votes in this area."
    )
    database.log_area("vote_clear", client, client.area)


def get_vote_results(votes):
    # Sort the votes, starting from the least votes ending with the most votes. Note that x[1] is a list of voters, hence the len().
    votes = sorted(votes.items(), key=lambda x: len(x[1]))
    msg = ""
    # Iterating through the votes...
    for key, value in votes:
        # Create a comma-separated list of people who voted for this person
        voters = ", ".join(value)
        num = len(value)
        s = "s" if num > 1 else ""
        msg += f"\n{num} vote{s} for {key} - voted by {voters}."

    # Get the maximum amount of votes someone received
    mx = len(votes[len(votes) - 1][1])
    # Determine a list of winners - usually it's just one winner, but there's multiple if it's a tie.
    winners = [k for k, v in votes if len(v) == mx]

    # If we have a tie...
    if len(winners) > 1:
        # Create a comma-separated list of winners
        tied = ", ".join(winners)
        # Display.
        msg += f"\n{tied} have tied for most votes."
    else:
        # Display the sole winner.
        msg += f"\n{winners[0]} has most votes."
    return msg


@mod_only(area_owners=True)
def ooc_cmd_vote_reveal(client, arg):
    """
    Reveal the number of votes, the voters and those with the highest amount of votes.
    Usage: /vote_reveal
    """
    if len(client.area.votes) == 0:
        raise ClientError("There are no votes to reveal in this area.")
    msg = "Votes have been revealed:"
    msg += get_vote_results(client.area.votes)
    client.area.broadcast_ooc(msg)
    client.send_ooc("Use /vote_clear for clearing.")
    database.log_area("vote_reveal", client, client.area)


@mod_only(area_owners=True)
def ooc_cmd_vote_check(client, arg):
    """
    Check the number of votes, the voters and those with the highest amount of votes privately with a message telling others you've done so.
    Usage: /vote_check
    """
    if len(client.area.votes) == 0:
        raise ClientError("There are no votes to check in this area.")
    client.area.broadcast_ooc(
        f"[{client.id}] {client.showname} has checked the votes in this area."
    )
    msg = "Votes in this area:"
    msg += get_vote_results(client.area.votes)
    client.send_ooc(msg)
    client.send_ooc(
        "Use /vote_clear for clearing, or /vote_reveal to reveal the results publicly."
    )
    database.log_area("vote_check", client, client.area)


@mod_only()
def ooc_cmd_rolla_reload(client, arg):
    """
    Reload ability dice sets from a configuration file.
    Usage: /rolla_reload
    """
    rolla_reload(client.area)
    client.send_ooc("Reloaded ability dice configuration.")
    database.log_area("rolla_reload", client, client.area)


def rolla_reload(area):
    try:
        import yaml

        with open("config/dice.yaml", "r") as dice:
            area.ability_dice = yaml.safe_load(dice)
    except Exception:
        raise ServerError(
            "There was an error parsing the ability dice configuration. Check your syntax."
        )


def ooc_cmd_rolla_set(client, arg):
    """
    Choose the set of ability dice to roll.
    Usage: /rolla_set <name>
    """
    if not hasattr(client.area, "ability_dice"):
        rolla_reload(client.area)
    available_sets = ", ".join(client.area.ability_dice.keys())
    if len(arg) == 0:
        raise ArgumentError(
            f"You must specify the ability set name.\nAvailable sets: {available_sets}"
        )
    elif arg not in client.area.ability_dice:
        raise ArgumentError(
            f"Invalid ability set '{arg}'.\nAvailable sets: {available_sets}"
        )
    client.ability_dice_set = arg
    client.send_ooc(f"Set ability set to {arg}.")


def rolla(ability_dice):
    max_roll = ability_dice["max"] if "max" in ability_dice else 6
    roll = random.randint(1, max_roll)
    ability = ability_dice[roll] if roll in ability_dice else "Nothing happens."
    return (roll, max_roll, ability)


def ooc_cmd_rolla(client, arg):
    """
    Roll a specially labeled set of dice (ability dice).
    Usage: /rolla
    """
    if not hasattr(client.area, "ability_dice"):
        rolla_reload(client.area)
    if not hasattr(client, "ability_dice_set"):
        raise ClientError(
            "You must set your ability set using /rolla_set <name>.")
    ability_dice = client.area.ability_dice[client.ability_dice_set]
    roll, max_roll, ability = rolla(ability_dice)
    client.area.broadcast_ooc(
        f"[{client.id}] {client.showname} rolled a {roll} (out of {max_roll}): {ability}."
    )
    database.log_area(
        "rolla", client, client.area, message=f"{roll} out of {max_roll}: {ability}"
    )


def ooc_cmd_coinflip(client, arg):
    """
    Flip a coin. The result is shown publicly.
    Usage: /coinflip
    """
    if len(arg) != 0:
        raise ArgumentError("This command has no arguments.")
    coin = ["heads", "tails"]
    flip = random.choice(coin)
    client.area.broadcast_ooc(
        f"[{client.id}] {client.showname} flipped a coin and got {flip}."
    )
    database.log_area("coinflip", client, client.area, message=flip)


def ooc_cmd_8ball(client, arg):
    """
    Answers a question. The result is shown publicly.
    Usage: /8ball <question>
    """

    arg = arg.strip()
    if len(arg) == 0:
        raise ArgumentError("You need to ask a question")
    if len(arg) > 128:
        raise ArgumentError("Your question is too long!")
    rolla_reload(client.area)
    ability_dice = client.area.ability_dice["8ball"]
    client.area.broadcast_ooc(
        f'{client.showname} asked the 8ball - "{arg}", and it responded: "{rolla(ability_dice)[2]}".'
    )


def ooc_cmd_rps(client, arg):
    """
    Starts a match of Rock Paper Scissors.
    If [choice] is not provided, view current RPS rules.
    Usage: /rps [choice]
    To abandon the match, use /rps cancel
    """
    # format:
    # [
    #   [a, b, c, ...] where 'a' beats 'b', 'c', ...
    # ]

    rps_rules = client.area.area_manager.rps_rules

    # Strip the input of blank spaces on edges 
    arg = arg.strip()
    
    # If doing /rps by itself, simply tell the user the rules.
    if not arg:
        msg = "RPS rules:"
        for i, rule in enumerate(rps_rules):
            msg += f"\n  {i+1}) "
            choice = rule[0]
            msg += choice
            if len(choice) > 1:
                losers = ', '.join(rule[1:])
                msg += f" beats {losers}"
        client.send_ooc(msg)
        return

    if arg.lower() in ["clear", "cancel"]:
        if client.rps_choice:
            client.area.broadcast_ooc(f'[{client.id}] {client.showname} no longer wants to play 🎲Rock Paper Scissors🎲... 🙁')
        client.rps_choice = ""
        client.send_ooc('You cleared your choice.')
        return

    # List of our available choices
    choices = []
    for rule in rps_rules:
        rule = rule[0].lower()
        if rule not in choices:
            choices.append(rule)
    picked = ""
    for choice in choices:
        # Exact match, can't get better than this. Break out of the loop
        if arg.lower() == choice:
            picked = choice
            break
        # Fuzzy match, queue up our pick but look if we can get something better
        if arg.lower() in choice:
            picked = choice
    
    if picked not in choices:
        raise ArgumentError(f"Invalid choice! Available choices are: {', '.join(choices)}")
    
    # If we already have made a rps choice before, simply silently swap our choice.
    if client.rps_choice:
        client.rps_choice = picked
        client.send_ooc(f'Swapped your choice to {client.rps_choice}!')
        return
        
    # Set our Rock Paper Scissors choice
    client.rps_choice = picked

    # Loop through clients in area to see if they're waiting on the challenge
    # TODO: this method is gonna be bug-prone, please fix.
    target = None
    for c in client.area.clients:
        if c == client:
            continue
        if c.rps_choice:
            target = c
            break

    # Look for our opponent if none is present
    if not target:
        msg = f'[{client.id}] {client.showname} wants to play 🎲Rock Paper Scissors🎲!\n❕ Do /rps [choice] to challenge them! ❕'
        client.area.broadcast_ooc(msg)
        client.send_ooc(f'You picked {client.rps_choice}!')
        return

    # Start constructing our output message
    msg = '🎲Rock Paper Scissors🎲'
    msg += f'\n  ◽ [{target.id}] {target.showname} picks {target.rps_choice}!'
    msg += f'\n  ◽ [{client.id}] {client.showname} picks {client.rps_choice}!'
    
    # Calculate our winner
    a = target.rps_choice.lower()
    b = client.rps_choice.lower()
    winner = None
    for rule in rps_rules:
        rule = [r.lower() for r in rule]
        choice = rule[0]
        losers = []
        if len(rule) > 1:
            losers = rule[1:]
        if a in choice and b in losers:
            winner = target
            break
        elif b in choice and a in losers:
            winner = client
            break

    # Congratulate our winner or announce a tie
    if winner:
        msg += f"\n  🏆[{winner.id}] {winner.showname} WINS!!!🏆"
    else:
        msg += f"\n  👔It's a tie!👔"

    # Announce the message!
    client.area.broadcast_ooc(msg)

    # Clear the game for our 2 contestants
    target.rps_choice = ""
    client.rps_choice = ""


@mod_only(area_owners=True)
def ooc_cmd_rps_rules(client, arg):
    """
    Review or change rps rules
    Usage:  /rps_rules - review current rules, indexed
            /rps_rules <add|new|+> [a beats b, c, d, ...] - add a new rule, or rules if the param is split by line break
            /rps_rules <del|remove|-> [index] - delete a rule at index
            /rps_rules <clear|clean|reset|wipe> - wipe all current rules
    """
    #client.area.area_manager.rps_rules

    # Strip the input of blank spaces on edges 
    arg = arg.strip()
    
    # If doing /rps_rules by itself, simply tell the user the rules.
    if not arg:
        ooc_cmd_rps(client, "")
        return
    
    try:
        args = arg.split(maxsplit=1)
        action = args[0]
        param = ""
        if len(args) > 1:
            param = args[1]
        if action.lower() in ["add", "new", "+"]:
            rules = param.splitlines()
            for rule in rules:
                newrule = rule.split("beats")
                newrule = [newrule[0].strip()] + newrule[1].strip().split(",")
                newrule = [a.strip() for a in newrule]
                client.area.area_manager.rps_rules.append(newrule)
                client.send_ooc(f"Added a new rule: {rule}")
        elif action.lower() in ["del", "remove", "-"]:
            index = int(param)-1
            if index < 0 or index >= len(client.area.area_manager.rps_rules):
                raise ArgumentError(
                    "Invalid index!"
                )
            client.send_ooc(f"Deleted a rule: {client.area.area_manager.rps_rules[index]}")
            client.area.area_manager.rps_rules.pop(index)
        elif action.lower() in ["clear", "clean", "reset", "wipe"]:
            client.send_ooc("Deleted all rules.")
            client.area.area_manager.rps_rules.clear()
        else:
            raise ArgumentError(
                "Invalid action!"
            )
    except ValueError:
        raise ArgumentError(
            "Invalid parameter!"
        )


def ooc_cmd_timer(client, arg):
    """
    Manage a countdown timer in the current area. Note that timer of ID `0` is hub-wide. All other timer ID's are local to area.
    Anyone can check ongoing timers, their status and time left using `/timer <id>`, so `/timer 0`.
    `[time]` can be formated as `10m5s`, or `"10 minutes 5 seconds"` (quotes included) - full list of time formats: https://pypi.org/project/pytimeparse/
    You can optionally add or subtract time, like so: `/timer 0 +5s` to add `5` seconds to timer id `0`.
    `start` starts the previously set timer, so `/timer 0 start`.
    `pause` OR `stop` pauses the timer that's currently running, so `/timer 0 pause`.
    `unset` OR `hide` hides the timer for it to no longer show up, so `/timer 0 hide`.
    Commands can also be passed - /cmd is a command that you want to run when the timer expires. That command will be added to the stack of commands to run.
    For example, `/timer 0 /timer 0 hide` will hide the timer when it expires. Adding `/timer 0 /h hello there` will also say "hello there" in hub chat as your client.
    If you want to clear all commands, use `/timer <id> /clear`
    Usage:
    /timer <id> [+][time] [start|pause/stop|unset/hide]
    /timer <id> /cmd
    """

    args = shlex.split(arg)
    if len(args) < 1:
        msg = "Currently active timers:"
        # Hub timer
        timer = client.area.area_manager.timer
        if timer.set:
            if timer.started:
                msg += f"\nTimer 0 is at {timer.target - arrow.get()}"
            else:
                msg += f"\nTimer 0 is at {timer.static}"
        # Area timers
        for timer_id, timer in enumerate(client.area.timers):
            if timer.set:
                if timer.started:
                    msg += f"\nTimer {timer_id+1} is at {timer.target - arrow.get()}"
                else:
                    msg += f"\nTimer {timer_id+1} is at {timer.static}"
        client.send_ooc(msg)
        return
    # TI packet specification:
    # TI#TimerID#Type#Value#%
    # TimerID = from 0 to 4 (5 possible timers total)
    # Type 0 = start/resume/sync timer at time
    # Type 1 = pause timer at time
    # Type 2 = show timer
    # Type 3 = hide timer
    # Value = Time to set on the timer
    timer_id = int(args[0])
    if timer_id < 0 or timer_id > 20:
        raise ArgumentError("Invalid ID. Usage: /timer <id>")
    if timer_id == 0:
        timer = client.area.area_manager.timer
    else:
        timer = client.area.timers[timer_id - 1]
    if len(args) < 2:
        if timer.set:
            if timer.started:
                client.send_ooc(
                    f"Timer {timer_id} is at {timer.target - arrow.get()}")
            else:
                client.send_ooc(f"Timer {timer_id} is at {timer.static}")
        else:
            client.send_ooc(f"Timer {timer_id} is unset.")
        return

    if not (client in client.area.owners) and not client.is_mod:
        raise ArgumentError(
            "Only CMs or GMs can modify timers. Usage: /timer <id>")
    if (
        timer_id == 0
        and not (client in client.area.area_manager.owners)
        and not client.is_mod
    ):
        raise ArgumentError(
            "Only GMs can set hub-wide timer ID 0. Usage: /timer <id>")

    command_arg = args[1]

    duration_arg = args[1]
    duration = pytimeparse.parse(duration_arg)
    if duration is not None:
        if timer.set:
            if timer.started:
                if not (duration_arg[0] == "+" or duration < 0):
                    timer.target = arrow.get()
                timer.target = timer.target.shift(seconds=duration)
                timer.static = timer.target - arrow.get()
            else:
                if not (duration_arg[0] == "+" or duration < 0):
                    timer.static = datetime.timedelta(0)
                timer.static += datetime.timedelta(seconds=duration)
        else:
            timer.static = datetime.timedelta(seconds=abs(duration))
            timer.set = True
        if len(args) > 2:
            command_arg = args[2]

    if not timer.set:
        client.send_ooc(f"Timer {timer_id} is not set in this area.")
        return

    if command_arg == "start" and not timer.started:
        timer.target = timer.static + arrow.get()
        timer.started = True
        client.send_ooc(f"Starting timer {timer_id}.")
    elif command_arg in ("pause", "stop") and timer.started:
        timer.static = timer.target - arrow.get()
        timer.started = False
        client.send_ooc(f"Stopping timer {timer_id}.")
    elif command_arg in ("unset", "hide"):
        timer.set = False
        timer.started = False
        timer.static = None
        timer.target = None
        timer.commands.clear()
        if timer.schedule:
            timer.schedule.cancel()
        client.send_ooc(f"Timer {timer_id} unset and hidden.")
        if timer_id == 0:
            client.area.area_manager.send_timer_set_time(timer_id, None)
        else:
            client.area.send_timer_set_time(timer_id, None)
    elif args[1][0] == "/":
        full = " ".join(args[1:])[1:]
        if full == "":
            txt = f"Timer {timer_id} commands:"
            for command in timer.commands:
                txt += f"  \n/{command}"
            txt += "\nThey will be called once the timer expires."
            client.send_ooc(txt)
            return
        if full.lower() == "clear":
            timer.commands.clear()
            client.send_ooc(f"Clearing all commands for Timer {timer_id}.")
            return

        cmd = full.split(" ")[0]
        called_function = f"ooc_cmd_{cmd}"
        if len(client.server.command_aliases) > 0 and not hasattr(
            commands, called_function
        ):
            if cmd in client.server.command_aliases:
                called_function = f"ooc_cmd_{client.server.command_aliases[cmd]}"
        if not hasattr(commands, called_function):
            client.send_ooc(
                f"[Timer {timer_id}] Invalid command: {cmd}. Use /help to find up-to-date commands."
            )
            return
        timer.commands.append(full)
        client.send_ooc(f"Adding command to Timer {timer_id}: /{full}")
        return

    # Send static time if applicable
    if timer.set:
        s = int(not timer.started)
        static_time = int(timer.static.total_seconds()) * 1000
        if timer_id == 0:
            client.area.area_manager.send_timer_set_time(timer_id, static_time, timer.started)
        else:
            client.area.send_timer_set_time(timer_id, static_time, timer.started)
        client.send_ooc(f"Timer {timer_id} is at {timer.static}")

        if timer_id == 0:
            timer.hub = client.area.area_manager
        else:
            timer.area = client.area

        timer.caller = client
        if timer.schedule:
            timer.schedule.cancel()
        if timer.started:
            timer.schedule = asyncio.get_running_loop().call_later(
                int(timer.static.total_seconds()), timer.timer_expired
            )


@mod_only(area_owners=True)
def ooc_cmd_demo(client, arg):
    """
    Usage:
    /demo <evidence_id> or /demo <evidence_name>
    Use /demo to stop demo
    """
    if arg == "":
        client.area.stop_demo()
        client.send_ooc("Stopping demo playback...")
        return
    if (time.time() * 1000 - client.last_demo_call) < 1000:
        client.send_ooc("Please wait a bit before calling /demo again!")
        return
    evidence = None
    if arg.isnumeric():
        arg = str(int(arg) - 1)
    for i, evi in enumerate(client.area.evi_list.evidences):
        if arg.lower() == evi.name.lower() or arg == str(i):
            evidence = evi
            break
    if not evidence:
        raise ArgumentError("Target evidence not found!")

    client.last_demo_call = time.time() * 1000
    client.area.demo.clear()

    desc = (
        evidence.desc.replace("<num>", "#")
        .replace("<and>", "&")
        .replace("<percent>", "%")
        .replace("<dollar>", "$")
    )
    packets = desc.split("%")
    for packet in packets:
        p_args = packet.split("#")
        p_args[0] = p_args[0].strip()
        if p_args[0] in ["MS", "CT", "MC", "BN", "HP", "RT", "wait", "GM", "ST"]:
            client.area.demo += [p_args]
        elif p_args[0].startswith("/"):  # It's a command!
            p_args = packet.split(" ")
            p_args[0] = p_args[0].strip()
            client.area.demo += [p_args]
    for c in client.area.clients:
        if c in client.area.owners:
            c.send_ooc(
                f"Starting demo playback using evidence '{evidence.name}'...")

    client.area.play_demo(client)


@mod_only(area_owners=True)
def ooc_cmd_trigger(client, arg):
    """
    Set up a trigger for this area which, when fulfilled, will call the command.
    `trig` is the trigger keyword. Available keywords are 'join', 'leave' and 'present id' where id is the evidence ID.
    `cmd` is the standard command name, such as 'lock' to call the `lock` command when trigger is fulfilled.
    `arg(s)` are the arguments of the command, so in `bg default`, `default` is the argument
    CM's, GM's and Mods are ignored by triggers.
    Usage:
    /trigger trig cmd arg(s)
    """
    if arg == "":
        msg = "This area's triggers are:"
        for key, value in client.area.triggers.items():
            msg += f'\nCall "{value}" on {key}'
        msg = "\nEvidence triggers:"
        for evidence in client.area.evi_list.evidences:
            # TODO: figure out why triggers.items() doesn't work here
            value = evidence.triggers["present"]
            if value != "":
                msg += f'\n💼{evidence.name}: Call "{value}" on present'
        client.send_ooc(msg)
        return
    if arg.lower().startswith("present "):
        args = arg.split(" ", 2)
        trig = args[0].lower()
        if len(args) <= 1:
            raise ArgumentError("Please provide target evidence!")
        _id = args[1]
        evidence = None
        if _id.isnumeric():
            _id = str(int(_id) - 1)
        for i, evi in enumerate(client.area.evi_list.evidences):
            if _id.lower() == evi.name.lower() or _id == str(i):
                evidence = evi
                break
        if not evidence:
            raise ArgumentError("Target evidence not found!")
        if len(args) <= 2:
            client.send_ooc(
                f'Call "{evidence.triggers[trig]}" on trigger "{trig}"')
            return
        val = args[2]
        evidence.triggers[trig] = val
        client.send_ooc(f'Changed to Call "{val}" on trigger "{trig}"')
    else:
        args = arg.split(" ", 1)
        trig = args[0].lower()
        if trig not in client.area.triggers:
            raise ArgumentError(f"Invalid trigger: {trig}")
        if len(args) <= 1:
            client.send_ooc(
                f'Call "{client.area.triggers[trig]}" on trigger "{trig}"')
            return
        val = args[1]
        client.area.triggers[trig] = val
        client.send_ooc(f'Changed to Call "{val}" on trigger "{trig}"')


def ooc_cmd_format_timer(client, arg):
    """
    Format the timer
    Usage: /format_timer <Timer_iD> <Format>
    """
    args = shlex.split(arg)
    try:
        args[0] = int(args[0])
    except:
        raise ArgumentError("Timer ID should be an integer")
    if args[0] == 0:
        if client.is_mod or client in client.area.area_manager.owners:
            timer = client.area.area_manager.timer
        else:
            client.send_ooc("You cannot change timer 0 format if you are not GM")
            return
    else:
        if (
            client.is_mod
            or client in client.area.area_manager.owners
            or client in client.area.owners
        ):
            timer = client.area.timers[args[0] - 1]
        else:
            client.send_ooc("You cannot change timer format if you are at least CM")
            return
    timer.format = args[1:]
    if timer.set:
        if timer.started:
            current_time = timer.target - arrow.get()
            current_time = int(current_time.total_seconds()) * 1000
        else:
            current_time = int(timer.static.total_seconds()) * 1000
        if args[0] == 0:
            client.area.area_manager.send_timer_set_time(args[0], current_time, timer.started)
        else:
            client.area.send_timer_set_time(args[0], current_time, timer.started)
    client.send_ooc(f"Timer {args[0]} format: '{args[1]}'")


def ooc_cmd_timer_interval(client, arg):
    """
    Set timer interval
    If timer interval is not written than will show default timer interval (16ms)
    Example: /timer_interval 1 15m
    Usage: /timer_interval <Timer_ID> <Interval>
    """
    args = shlex.split(arg)
    try:
        args[0] = int(args[0])
    except:
        raise ArgumentError("Timer ID should be an integer")
    if args[0] == 0:
        if client.is_mod or client in client.area.area_manager.owners:
            timer = client.area.area_manager.timer
        else:
            client.send_ooc("You cannot change timer 0 interval if you are not GM")
            return
    else:
        if (
            client.is_mod
            or client in client.area.area_manager.owners
            or client in client.area.owners
        ):
            timer = client.area.timers[args[0] - 1]
        else:
            client.send_ooc("You cannot change timer interval if you are at least CM")
            return
    try:
        if len(args) == 1:
            timer.interval = 16 
        else:
            timer.interval = pytimeparse.parse(args[1]) * 1000
    except:
        raise ArgumentError("Interval value not valid!")
    if timer.set:
        client.send_timer_set_interval(args[0], timer)
    client.send_ooc(f"Timer {args[0]} interval is set to '{args[1]}'")
